<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaljetService
{
    protected string $baseUrl;
    protected string $user;
    protected string $pass;
    protected int $empId;
    protected int $depId;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.paljet.base_url'), '/');
        $this->user    = config('services.paljet.user');
        $this->pass    = config('services.paljet.pass');
        $this->empId   = (int) config('services.paljet.emp_id', 1);
        $this->depId   = (int) config('services.paljet.dep_id', 8);
        $this->timeout = (int) config('services.paljet.timeout', 30);
    }

    /**
     * Cliente HTTP base con autenticación y headers comunes.
     */
protected function client()
{
    return Http::withBasicAuth($this->user, $this->pass)
        ->acceptJson()
        ->asJson() // 👈 ESTA ES LA CLAVE
        ->withHeaders([
            'EmpID' => $this->empId,
        ])
        ->timeout($this->timeout);
}

    /**
     * Obtener artículos del WS de Paljet.
     * Si se pasa 'categoria', el filtro se aplica en PHP porque el WS de Paljet
     * devuelve error 500 al recibir ese parámetro.
     */
    public function getArticulos(array $filtros = []): array
    {
        $page        = (int) ($filtros['page'] ?? 0);
        $size        = (int) ($filtros['size'] ?? 20);
        $categoriaId = isset($filtros['categoria']) ? (int) $filtros['categoria'] : null;

        // Quitar 'categoria' antes de enviar a Paljet (su WS crashea con ese param)
        $params = array_merge(
            ['dep_id' => $this->depId, 'page' => $page, 'size' => $size],
            array_diff_key($filtros, ['categoria' => true])
        );

        // Al filtrar por categoría en PHP necesitamos todos los artículos de una vez
        if ($categoriaId !== null) {
            $params['page'] = 0;
            $params['size'] = 500;
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/articulos", $params);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al obtener artículos', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => 'Error al consultar artículos en Paljet', 'status' => $response->status()];
            }

            $data = $response->json();

            // Agregar imagen_url, stock_disponible y ultimas_unidades a cada artículo.
            // Se obtiene el stock real de dep_id=8 en una sola llamada batch.
            if (!empty($data['content'])) {
                $stockMap = $this->getStockMapDeposito();
                $data['content'] = array_map(
                    fn($a) => $this->enrichArticulo($a, $stockMap[$a['id']] ?? null),
                    $data['content']
                );
            }

            // Filtro de categoría en PHP (incluye subcategorías)
            if ($categoriaId !== null) {
                $catIds = $this->getCategoryDescendantIds($categoriaId);

                $filtered = array_values(array_filter(
                    $data['content'] ?? [],
                    fn($a) => isset($a['categoria']['id']) && in_array($a['categoria']['id'], $catIds)
                ));

                $total = count($filtered);
                return [
                    'content'       => array_slice($filtered, $page * $size, $size),
                    'totalElements' => $total,
                    'totalPages'    => (int) ceil($total / max($size, 1)),
                    'number'        => $page,
                    'size'          => $size,
                ];
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener artículos', [
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo conectar con el servicio de Paljet'];
        }
    }

    /**
     * Devuelve el ID de la categoría dada y todos sus descendientes.
     * Necesario para filtrar artículos por categoría padre.
     */
    protected function getCategoryDescendantIds(int $categoryId): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/categorias");
            if (!$response->successful()) {
                return [$categoryId];
            }

            $tree      = $response->json();
            $rootHijos = $tree[0]['hijos'] ?? [];

            $found = $this->findSubtree($rootHijos, $categoryId);
            return $found !== null ? $this->collectAllIds($found) : [$categoryId];
        } catch (\Throwable $e) {
            return [$categoryId];
        }
    }

    /**
     * Busca recursivamente el nodo con el ID dado y devuelve ese nodo.
     */
    protected function findSubtree(array $nodes, int $targetId): ?array
    {
        foreach ($nodes as $node) {
            if ($node['art_cat_id'] === $targetId) {
                return $node;
            }
            $found = $this->findSubtree($node['hijos'] ?? [], $targetId);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Recolecta todos los IDs de un nodo y sus descendientes.
     */
    protected function collectAllIds(array $node): array
    {
        $ids = [$node['art_cat_id']];
        foreach ($node['hijos'] ?? [] as $hijo) {
            $ids = array_merge($ids, $this->collectAllIds($hijo));
        }
        return $ids;
    }

    /**
     * Obtener la imagen de un artículo por código.
     * Devuelve ['body' => binary, 'type' => 'image/jpeg'] o ['error' => ...]
     */
    public function getImagenArticulo(string $codigo): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/imagenes/articulos", [
                'codigo' => $codigo,
            ]);

            if (!$response->successful()) {
                return ['error' => 'Imagen no disponible', 'status' => $response->status()];
            }

            $body = $response->body();

            if (empty($body)) {
                return ['error' => 'Sin imagen', 'status' => 404];
            }

            return [
                'body' => $body,
                'type' => $response->header('Content-Type') ?: 'image/jpeg',
            ];
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener imagen', [
                'codigo'  => $codigo,
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo obtener la imagen'];
        }
    }

    /**
     * Obtener el árbol de categorías filtrado a las que tienen artículos publicados.
     */
    public function getCategorias(): array
    {
        try {
            // 1. Traer todos los artículos publicados para obtener sus categoria IDs
            $artResponse = $this->client()->get("{$this->baseUrl}/articulos", [
                'dep_id'       => $this->depId,
                'publica_web'  => 'true',
                'solo_activos' => 'true',
                'size'         => 500,
                'page'         => 0,
            ]);

            if (!$artResponse->successful()) {
                return ['error' => 'Error al obtener artículos', 'status' => $artResponse->status()];
            }

            $articulos     = $artResponse->json()['content'] ?? [];
            $activeCatIds  = [];
            foreach ($articulos as $art) {
                $cat = $art['categoria'] ?? null;
                if (isset($cat['id'])) {
                    $activeCatIds[$cat['id']] = true;
                }
            }

            // 2. Traer el árbol completo de categorías de Paljet
            $catResponse = $this->client()->get("{$this->baseUrl}/categorias");

            if (!$catResponse->successful()) {
                return ['error' => 'Error al obtener categorías', 'status' => $catResponse->status()];
            }

            $tree     = $catResponse->json();
            $rootHijos = (is_array($tree) && isset($tree[0]['hijos'])) ? $tree[0]['hijos'] : [];

            // 3. Podar el árbol: solo nodos con al menos un descendiente (o ellos mismos) activo
            return $this->pruneCategoriasTree($rootHijos, $activeCatIds);
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener categorías', [
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo conectar con el servicio de Paljet'];
        }
    }

    /**
     * Filtra recursivamente el árbol de categorías de Paljet.
     */
    protected function pruneCategoriasTree(array $nodes, array $activeCatIds): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $id    = $node['art_cat_id'];
            $hijos = $this->pruneCategoriasTree($node['hijos'] ?? [], $activeCatIds);

            if (isset($activeCatIds[$id]) || count($hijos) > 0) {
                $result[] = [
                    'id'     => $id,
                    'nombre' => $node['nombre'],
                    'hijos'  => $hijos,
                ];
            }
        }
        return $result;
    }

    /**
     * Busca un cliente en Paljet por CUIT o DNI.
     * Retorna el cli_id de Paljet o null si no se encuentra.
     */
    public function buscarClientePorCuitODni(string $cuit = null, string $dni = null): ?int
    {
        // Normalizar: quitar guiones y espacios
        $cuitLimpio = $cuit ? preg_replace('/[^0-9]/', '', $cuit) : null;
        $dniLimpio  = $dni  ? preg_replace('/[^0-9]/', '', $dni)  : null;

        // Intentar primero por CUIT
        if ($cuitLimpio) {
            $id = $this->buscarClienteEnPaljet('cuit', $cuitLimpio);
            if ($id !== null) return $id;
        }

        // Fallback por DNI (usando razon_social o cod_externo puede variar — aquí usamos cuit con el DNI)
        if ($dniLimpio) {
            $id = $this->buscarClienteEnPaljet('cuit', $dniLimpio);
            if ($id !== null) return $id;
        }

        Log::warning('Paljet WS - Cliente no encontrado por CUIT/DNI', [
            'cuit' => $cuitLimpio,
            'dni'  => $dniLimpio,
        ]);

        return null;
    }

/**
     * Crea un cliente en Paljet y retorna su cli_id, o null si falla.
     */
/**
     * Crea un cliente en Paljet y retorna su cli_id, o null si falla.
     */
    public function crearCliente(array $datos): ?int
    {
        // =========================
        // 1. Normalizar CUIT
        // =========================
        $cuitRaw    = preg_replace('/[^0-9]/', '', $datos['cuit'] ?? '');
        $cuitLimpio = strlen($cuitRaw) === 11 ? $cuitRaw : '';

        // =========================
        // 2. Mapear condición IVA
        // =========================
        $ivaMap = [
            'Consumidor Final'      => 'CF',
            'Responsable Inscripto' => 'RI',
            'Monotributista'        => 'MO',
            'Exento'                => 'EX',
            'Resp. No Inscripto'    => 'NI',
            'No Responsable'        => 'NR',
        ];
        $condIvaLocal = $datos['condicion_iva'] ?? 'Consumidor Final';
        $ivaId        = $ivaMap[$condIvaLocal] ?? 'CF';

        // =========================
        // 3. Determinar Sexo (M, F, E)
        // =========================
        $sexo = 'M'; 
        if ($cuitLimpio) {
            $prefijo = substr($cuitLimpio, 0, 2);
            if ($prefijo === '27') {
                $sexo = 'F';
            } elseif (in_array($prefijo, ['30', '33', '34'])) {
                $sexo = 'E';
            }
        }

        // =========================
        // 4. Construcción del Body
        // =========================
        $body = [
            'cod_cli'           => '', 
            'cli_tipo_id'       => 1, 
            'rz'                => $datos['nombre'] ?? 'Cliente Web',
            'nom_fantasia'      => $datos['nombre'] ?? 'Cliente Web',
            'cuit'              => $cuitLimpio,
            'iva_id'            => $ivaId,
            'tipo_iibb_id'      => 0,
            'sexo'              => $sexo, 
            'copia_nota_cpr'    => false, 
            'muestra_nota_cpr'  => false,
            'crediticio'        => false,
            'ctacorrentista'    => false,
            'ctacte_tipo_id'    => 1, 
            'discrimina_bonif'  => false,
            'es_cli_generico'   => false,
        ];

        // =========================
        // 5. Teléfonos
        // =========================
        if (!empty($datos['telefono'])) {
            $telefonoLimpio = preg_replace('/[^0-9]/', '', $datos['telefono']);
            $body['telefonos'] = [[
                'numero'        => $telefonoLimpio,
                'tel_clasif_id' => 'CEL', 
                'por_defecto'   => true, 
            ]];
        } else {
            $body['telefonos'] = [];
        }

        // =========================
        // 6. Emails
        // =========================
        if (!empty($datos['email'])) {
            $body['emails'] = [[
                'email'           => $datos['email'],
                'email_clasif_id' => 'PE', 
                'por_defecto'     => true,
            ]];
        } else {
            $body['emails'] = [];
        }

        // =========================
        // 7. Domicilios
        // =========================
        $locId = 77; 
        if (!empty($datos['envio'])) {
            $envio = $datos['envio'];
            $calle = $envio['calle'] ?? 'WEB';
            $nro   = (int) ($envio['numero'] ?? 0);
            $cp    = $envio['codigo_postal'] ?? '';
            $clasif = 'DE'; 
        } else {
            $calle = 'RETIRO WEB';
            $nro   = 0;
            $cp    = '';
            $clasif = 'DP'; 
        }

        $body['domicilios'] = [[
            'calle'         => $calle,
            'calle_nro'     => $nro,
            'cp_nuevo'      => $cp,
            'dom'           => trim("$calle $nro"),
            'dom_clasif_id' => $clasif,
            'loc_id'        => $locId,
            'por_defecto'   => true,
            'entre_calle'   => '',
            'partido'       => '',
            'latitud'       => '',
            'longitud'      => '',
            'local'         => 0,
        ]];

        // =========================
        // 8. Enviar a Paljet
        // =========================
        try {
            $response = $this->client()->post("{$this->baseUrl}/clientes", $body);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al crear cliente', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'sent'   => $body,
                ]);
                return null;
            }

            $data = $response->json();
            
            // --- EXTRACCIÓN DEL ID (ACTUALIZADA SEGÚN TU ÚLTIMO LOG) ---
            $newId = null;

            // 1. Intentamos el campo exacto que vino en tu log: "cli_id"
            if (isset($data['cli_id'])) {
                $newId = $data['cli_id'];
            }
            // 2. Backup: HAL-JSON (clienteResources)
            elseif (isset($data['_embedded']['clienteResources'][0]['cliId'])) {
                $newId = $data['_embedded']['clienteResources'][0]['cliId'];
            } 
            // 3. Backup: Otras variantes comunes
            elseif (isset($data['id'])) {
                $newId = $data['id'];
            } 
            elseif (isset($data['cliId'])) {
                $newId = $data['cliId'];
            }

            if ($newId) {
                Log::info('Paljet WS - Cliente creado y ID capturado', [
                    'paljet_id' => $newId,
                    'nombre'    => $body['rz'],
                ]);
                return (int) $newId;
            }

            Log::error('Paljet WS - ID no hallado en la respuesta', ['respuesta' => $data]);
            return null;

        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al crear cliente', [
                'message' => $e->getMessage(),
                'sent'    => $body,
            ]);
            return null;
        }
    }

private function buscarClienteEnPaljet(string $campo, string $valor): ?int
{
    try {
        $response = $this->client()->get("{$this->baseUrl}/clientes", [
            $campo => $valor,
            'size' => 1,
            'page' => 0,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $content = $response->json()['content'] ?? [];

        if (empty($content)) {
            return null;
        }

        $cliente = $content[0];

        // 🔥 Captura robusta del ID
        if (isset($cliente['cli_id'])) {
            return (int) $cliente['cli_id'];
        }

        if (isset($cliente['cliId'])) {
            return (int) $cliente['cliId'];
        }

        if (isset($cliente['id'])) {
            return (int) $cliente['id'];
        }

        return null;

    } catch (\Throwable $e) {
        return null;
    }
}

    /**
     * Envía un pedido web a Paljet.
     * $items = [['art_id' => 123, 'cantidad' => 2, 'pr_final' => 125000.00], ...]
     * Retorna el ID del pedido en Paljet o null si falla.
     */
/**
 * Envía un pedido web a Paljet.
 */
/**
 * Envía un pedido web a Paljet con blindaje de datos dinámicos y logs detallados.
 * * @param int $paljetCliId
 * @param array $items
 * @param int $domicilioId
 * @param string|null $nota
 * @param int|null $condVtaId
 * @return int|null ID del pedido generado en Paljet
 */
public function enviarPedidoWeb(
    
    int $paljetCliId,
    array $items,
    int $domicilioId,
    string $nota = null,
    int $condVtaId = null
): ?int {
    if (empty($items)) {
        Log::warning('Paljet WS - Intento de enviar pedido sin items', [
            'cliente' => $paljetCliId
        ]);
        return null;
    }
    Log::error('ENTRE A LA VERSION NUEVA DE ENVIAR PEDIDO WEB');
    $notifEmail = config('services.paljet.notif_email');

    // Construcción del cuerpo del pedido
    // dep_id: 8 apunta a Playa Unión (PV 3)
    // loc_id: 174 apunta a la localidad Playa Unión
    $body = [
        'cliente'     => $paljetCliId,
        'dep_id'      => $this->depId,
        'cond_vta_id' => $condVtaId ?? (int) config('services.paljet.web_cond_vta', 1),
        'loc_id'      => (int) config('services.paljet.web_loc_id', 174),
        'domicilio'   => $domicilioId,
        'detalle'     => array_map(function ($i) {
            return [
                'articulo' => (int) $i['art_id'],
                'cantidad' => (float) $i['cantidad'],
                'prFinal'  => (float) $i['pr_final'],
            ];
        }, $items),
    ];

    if ($notifEmail) {
        $body['mailDestinatarios'] = $notifEmail;
    }

    if ($nota) {
        $body['nota'] = [$nota];
    }

    try {
        Log::info('Paljet WS - Iniciando envío de pedido web', [
            'url'  => "{$this->baseUrl}/pedido/web",
            'body' => $body,
        ]);

        $response = $this->client()->post("{$this->baseUrl}/pedido/web", $body);

        $status = $response->status();
        $raw    = $response->body();

        Log::info('Paljet WS - Respuesta recibida', [
            'status' => $status,
            'body'   => $raw,
        ]);

        if (!$response->successful()) {
            Log::error('Paljet WS - El servidor rechazó el pedido web', [
                'status' => $status,
                'body'   => $raw,
                'sent'   => $body,
            ]);
            return null;
        }

        $data = $response->json();

        // 🔥 Captura robusta del ID del pedido (Paljet puede variar el nombre del campo)
        $idHallado = null;

        if (isset($data['id'])) {
            $idHallado = (int) $data['id'];
        } elseif (isset($data['pedido_id'])) {
            $idHallado = (int) $data['pedido_id'];
        } elseif (isset($data['ped_id'])) {
            $idHallado = (int) $data['ped_id'];
        }

        if ($idHallado) {
            Log::info("Paljet WS - Pedido creado exitosamente", ['paljet_pedido_id' => $idHallado]);
            return $idHallado;
        }

        Log::warning('Paljet WS - El pedido se envió pero no se reconoció el ID en el JSON', [
            'response' => $data
        ]);

        return null;

    } catch (\Throwable $e) {
        Log::error('Paljet WS - Excepción crítica durante el POST del pedido', [
            'message' => $e->getMessage(),
            'line'    => $e->getLine(),
            'sent'    => $body,
        ]);
        return null;
    }
}

/**
 * Genera el pedido web en Paljet a partir de un Pedido local.
 *
 * @param  \App\Models\Pedido  $pedido
 * @return int|null  ID del pedido en Paljet, o null si falla.
 */
/**
 * Genera el pedido web en Paljet a partir de un Pedido local.
 *
 * @param  \App\Models\Pedido  $pedido
 * @return int|null  ID del pedido en Paljet, o null si falla.
 */
public function generarFacturaDePedido(\App\Models\Pedido $pedido): ?int
{
    Log::info("Paljet - Iniciando generación factura pedido {$pedido->id}");

    // =========================
    // 1. Construir items Paljet
    // =========================

    $itemsDirectos = \App\Models\PedidoItem::where('pedido_id', $pedido->id)
        ->whereNotNull('paljet_art_id')
        ->get()
        ->map(fn($i) => [
            'art_id'   => (int) $i->paljet_art_id,
            'cantidad' => (float) $i->cantidad,
            'pr_final' => (float) $i->precio_unitario,
        ]);

    $itemsContenedor = \Illuminate\Support\Facades\DB::table('pedido_items')
        ->join('productos', 'pedido_items.producto_id', '=', 'productos.id')
        ->where('pedido_items.pedido_id', $pedido->id)
        ->whereNull('pedido_items.paljet_art_id')
        ->whereNotNull('productos.paljet_art_id')
        ->select(
            'pedido_items.cantidad',
            'pedido_items.precio_unitario',
            'productos.paljet_art_id as paljet_art_id'
        )
        ->get()
        ->map(fn($i) => [
            'art_id'   => (int) $i->paljet_art_id,
            'cantidad' => (float) $i->cantidad,
            'pr_final' => (float) $i->precio_unitario,
        ]);

    $paljetItems = $itemsDirectos
        ->merge($itemsContenedor)
        ->values()
        ->all();

    Log::info("Paljet - Items construidos", [
        'pedido_id' => $pedido->id,
        'items'     => $paljetItems
    ]);

    if (empty($paljetItems)) {
        Log::warning("Paljet - Pedido {$pedido->id} sin artículos Paljet.");
        return null;
    }

    // =========================
    // 2. Buscar / Crear cliente
    // =========================

    $paljetCliId = $this->buscarClientePorCuitODni(
        $pedido->cuit_contacto,
        $pedido->dni_contacto
    );

    if (!$paljetCliId) {
        Log::info("Paljet - Cliente no existe, creando...");

        $paljetCliId = $this->crearCliente([
            'nombre'        => trim($pedido->nombre_contacto),
            'email'         => $pedido->email_contacto,
            'telefono'      => $pedido->telefono_contacto,
            'cuit'          => $pedido->cuit_contacto,
            'dni'           => $pedido->dni_contacto,
            'condicion_iva' => $pedido->condicion_iva_contacto ?? 'Consumidor Final',
        ]);
    }

    Log::info("Paljet - Cliente detectado", [
        'pedido_id'   => $pedido->id,
        'paljetCliId' => $paljetCliId
    ]);

    if (!$paljetCliId) {
        Log::error("Paljet - No se pudo obtener/crear cliente para pedido {$pedido->id}");
        return null;
    }

    // =========================
    // 3. Obtener domicilio
    // =========================

    $paljetDomId = $this->obtenerDomicilioIdDefault($paljetCliId);

    Log::info("Paljet - Domicilio detectado", [
        'cliente' => $paljetCliId,
        'dom_id'  => $paljetDomId
    ]);

    if (!$paljetDomId) {
        Log::error("Paljet - Cliente {$paljetCliId} sin domicilio válido.");
        return null;
    }

    // =========================
    // 4. Obtener condición venta
    // =========================

    $paljetCondVtaId = $this->obtenerCondVtaIdDefault($paljetCliId);

    Log::info("Paljet - Condición venta detectada", [
        'cliente'     => $paljetCliId,
        'cond_vta_id' => $paljetCondVtaId
    ]);

    if (!$paljetCondVtaId) {
        Log::error("Paljet - Cliente {$paljetCliId} sin condición de venta válida.");
        return null;
    }

    // =========================
    // 5. Enviar pedido web
    // =========================

    try {

        $paljetPedidoId = $this->enviarPedidoWeb(
            $paljetCliId,
            $paljetItems,
            $paljetDomId,
            $pedido->nota_cliente ?: null,
            $paljetCondVtaId
        );

        Log::info("Paljet - Resultado enviarPedidoWeb", [
            'pedido_id' => $pedido->id,
            'resultado' => $paljetPedidoId
        ]);

        if (!$paljetPedidoId) {
            Log::error("Paljet - Pedido rechazado por API", [
                'pedido_id' => $pedido->id
            ]);
            return null;
        }

        $pedido->update(['paljet_pedido_id' => $paljetPedidoId]);

        Log::info("Paljet - Pedido web enviado OK", [
            'pedido_id'        => $pedido->id,
            'paljet_pedido_id' => $paljetPedidoId,
        ]);

        return $paljetPedidoId;

    } catch (\Throwable $e) {

        Log::error("Paljet - Excepción al enviar pedido", [
            'pedido_id' => $pedido->id,
            'error'     => $e->getMessage()
        ]);

        return null;
    }
}

    /**
     * Obtiene precio final y disponibilidad de un artículo para validar un pedido web.
     *
     * Comportamiento:
     *  - Si Paljet no responde → retorna null (soft fail: el caller usa el precio del frontend).
     *  - Si responde → retorna ['pr_final', 'admin_existencia', 'disponible'].
     *
     * 'admin_existencia' = true  → Paljet gestiona stock para ese artículo → validar 'disponible'.
     * 'admin_existencia' = false → sin restricción de stock.
     */
    public function validarArticuloParaPedido(int $artId): ?array
    {
        $art = $this->getArticulo($artId);

        if (isset($art['error'])) {
            return null;
        }

        // Extraer pr_final: puede estar en la raíz del artículo o dentro del array 'listas'
        $prFinal = null;

        if (isset($art['pr_final']) && (float) $art['pr_final'] > 0) {
            $prFinal = (float) $art['pr_final'];
        } elseif (!empty($art['listas']) && is_array($art['listas'])) {
            foreach ($art['listas'] as $lista) {
                if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                    $prFinal = (float) $lista['pr_final'];
                    break;
                }
            }
        } elseif (isset($art['pr_vta']) && (float) $art['pr_vta'] > 0) {
            $prFinal = (float) $art['pr_vta'];
        }

        // Stock real del depósito principal (no viene en el response de /articulos)
        $adminExistencia = (bool) ($art['admin_existencia'] ?? false);
        $disponible      = 0.0;

        if ($adminExistencia) {
            $disponible = $this->getStockDeposito($artId) ?? 0.0;
        }

        Log::debug('Paljet - validarArticuloParaPedido', [
            'art_id'            => $artId,
            'pr_final_extraido' => $prFinal,
            'admin_existencia'  => $adminExistencia,
            'disponible'        => $disponible,
        ]);

        return [
            'pr_final'         => $prFinal,
            'admin_existencia' => $adminExistencia,
            'disponible'       => $disponible,
        ];
    }

    /**
     * Obtener un artículo por ID.
     */
    public function getArticulo(int $artId): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/articulos", [
                'art_id'  => $artId,
                'dep_id'  => $this->depId,
                'include' => 'listas',
            ]);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al obtener artículo', [
                    'art_id' => $artId,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => 'Artículo no encontrado', 'status' => $response->status()];
            }

            $data = $response->json();
            $content = $data['content'] ?? [];

            if (empty($content)) {
                return ['error' => 'Artículo no encontrado', 'status' => 404];
            }

            $articulo = $content[0];
            $stock    = $this->getStockDeposito((int) $articulo['id']);
            return $this->enrichArticulo($articulo, $stock);
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener artículo', [
                'art_id'  => $artId,
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo conectar con el servicio de Paljet'];
        }
    }

    /**
     * Enriquece un artículo de Paljet con campos extra para el frontend:
     *  - imagen_url
     *  - stock_disponible  (int si Paljet gestiona stock, null si no aplica)
     *  - ultimas_unidades  (true cuando quedan <= PALJET_STOCK_ALERTA unidades)
     *
     * $stockOverride: valor real de 'disponible' obtenido del endpoint de stock.
     * Si es null, se intenta con los campos del artículo (suelen venir vacíos en listados).
     */
    private function enrichArticulo(array $articulo, ?float $stockOverride = null): array
    {
        // Imagen
        $codigo = $articulo['codigo'] ?? null;
        $articulo['imagen_url'] = $codigo
            ? url("/api/catalogo/{$codigo}/imagen")
            : null;

        // Stock
        $adminExistencia = (bool) ($articulo['admin_existencia'] ?? false);

        if ($adminExistencia) {
            $disponible = $stockOverride ?? $articulo['disponible'] ?? $articulo['existencia'] ?? null;
            $stock      = $disponible !== null ? (int) $disponible : null;
            $umbral     = (int) config('services.paljet.stock_alerta', 3);

            $articulo['stock_disponible'] = $stock;
            $articulo['ultimas_unidades'] = $stock !== null && $stock > 0 && $stock <= $umbral;
        } else {
            $articulo['stock_disponible'] = null;
            $articulo['ultimas_unidades'] = false;
        }

        return $articulo;
    }

    /**
     * Retorna el stock disponible de un artículo en el depósito principal (dep_id=8).
     * Usa el endpoint /articulos/{artId}/stock que devuelve stock por depósito.
     * Retorna null si no se puede obtener.
     */
    private function getStockDeposito(int $artId): ?float
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/articulos/{$artId}/stock");

            if (!$response->successful()) {
                return null;
            }

            $entries = $response->json();

            if (!is_array($entries)) {
                return null;
            }

            foreach ($entries as $entry) {
                if (($entry['deposito']['dep_id'] ?? null) === $this->depId) {
                    return (float) ($entry['disponible'] ?? 0);
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Devuelve todos los artículos publicados en web cuyo stock en dep_id=8 es 0.
     * Solo incluye artículos con admin_existencia=true (Paljet gestiona su stock).
     * Paginación en PHP sobre el array filtrado.
     */
    public function getArticulosSinStock(int $page = 0, int $size = 100): array
    {
        try {
            // 1. Traer hasta 500 artículos publicados (cubre el catálogo actual ~231)
            $response = $this->client()->get("{$this->baseUrl}/articulos", [
                'dep_id'       => $this->depId,
                'publica_web'  => 'true',
                'solo_activos' => 'true',
                'size'         => 500,
                'page'         => 0,
            ]);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al obtener artículos sin stock', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => 'Error al consultar artículos en Paljet', 'status' => $response->status()];
            }

            $articulos = $response->json()['content'] ?? [];

            // 2. Mapa de stock dep_id=8 en una sola llamada
            $stockMap = $this->getStockMapDeposito();

            // 3. Filtrar: gestiona existencia Y stock = 0 (o ausente del mapa = nunca tuvo)
            $sinStock = array_values(array_filter($articulos, function ($art) use ($stockMap) {
                if (!($art['admin_existencia'] ?? false)) {
                    return false;
                }
                $artId      = (int) ($art['id'] ?? 0);
                $disponible = $stockMap[$artId] ?? 0.0;
                return (float) $disponible === 0.0;
            }));

            // 4. Dar forma al response (campos mínimos requeridos por el frontend)
            $sinStock = array_map(function ($art) {
                $marca   = $art['marca']   ?? null;
                $familia = $art['familia'] ?? null;

                return [
                    'art_id'          => (int) $art['id'],
                    'codigo'          => $art['codigo']      ?? null,
                    'descripcion'     => $art['descripcion'] ?? null,
                    'marca'           => is_array($marca)   ? ($marca['nombre']   ?? null) : $marca,
                    'familia'         => is_array($familia) ? ($familia['nombre'] ?? null) : $familia,
                    'stock_disponible'=> 0,
                ];
            }, $sinStock);

            // 5. Paginación en PHP
            $total     = count($sinStock);
            $paginated = array_slice($sinStock, $page * $size, $size);

            return [
                'content'       => $paginated,
                'totalElements' => $total,
                'totalPages'    => (int) ceil($total / max($size, 1)),
                'number'        => $page,
                'size'          => $size,
            ];
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener artículos sin stock', [
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo conectar con el servicio de Paljet'];
        }
    }

    /**
     * Retorna un mapa [art_id => disponible] con el stock de dep_id=8
     * para todos los artículos publicados en web.
     * Se hace en UNA sola llamada al endpoint /stock para enriquecer listados.
     */
    private function getStockMapDeposito(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/stock", [
                'depositos'   => $this->depId,
                'publica_web' => 'true',
                'size'        => 500,
                'page'        => 0,
            ]);

            if (!$response->successful()) {
                return [];
            }

            $entries = $response->json()['content'] ?? [];
            $map     = [];

            foreach ($entries as $entry) {
                $artId = $entry['articulo']['art_id'] ?? null;
                if ($artId !== null) {
                    $map[(int) $artId] = (float) ($entry['disponible'] ?? 0);
                }
            }

            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
/**
     * Devuelve los artículos de Paljet que están en la lista de ofertas local.
     * Filtra automáticamente los que tienen precio <= 0.
     *
     * @param  int[]  $artIds  Lista de paljet_art_id marcados como en_oferta.
     */
    /**
     * $ofertaMap = [ paljet_art_id => precio_oferta|null, ... ]
     *              Se construye en el caller desde la tabla paljet_ofertas.
     */
    public function getArticulosEnOferta(array $artIds, array $ofertaMap = []): array
    {
        if (empty($artIds)) {
            return ['content' => [], 'totalElements' => 0, 'totalPages' => 0, 'number' => 0, 'size' => 0];
        }

        try {
            $response = $this->client()->get("{$this->baseUrl}/articulos", [
                'dep_id'       => $this->depId,
                'publica_web'  => 'true',
                'solo_activos' => 'true',
                'size'         => 500,
                'page'         => 0,
                'include'      => 'listas',
            ]);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al obtener artículos en oferta', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['error' => 'Error al consultar artículos en Paljet', 'status' => $response->status()];
            }

            $articulos = $response->json()['content'] ?? [];
            $stockMap  = $this->getStockMapDeposito();
            $artIdSet  = array_flip($artIds);

            $filtered = array_values(array_filter($articulos, function ($art) use ($artIdSet) {
                if (!isset($artIdSet[(int) $art['id']])) {
                    return false;
                }

                // Debe tener precio Paljet > 0
                $precio = 0;
                if (!empty($art['listas']) && is_array($art['listas'])) {
                    foreach ($art['listas'] as $lista) {
                        if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                            $precio = (float) $lista['pr_final'];
                            break;
                        }
                    }
                }
                if ($precio === 0 && isset($art['pr_final']) && (float) $art['pr_final'] > 0) {
                    $precio = (float) $art['pr_final'];
                }

                return $precio > 0;
            }));

            // Enriquecer con imagen, stock y precios de oferta
            $filtered = array_map(function ($art) use ($stockMap, $ofertaMap) {
                $artId = (int) $art['id'];
                $art   = $this->enrichArticulo($art, $stockMap[$artId] ?? null);

                // precio_original = lo que dice Paljet
                $precioOriginal = 0;
                if (!empty($art['listas']) && is_array($art['listas'])) {
                    foreach ($art['listas'] as $lista) {
                        if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                            $precioOriginal = (float) $lista['pr_final'];
                            break;
                        }
                    }
                }
                if ($precioOriginal === 0 && isset($art['pr_final'])) {
                    $precioOriginal = (float) $art['pr_final'];
                }

                $precioOferta = isset($ofertaMap[$artId]) && $ofertaMap[$artId] > 0
                    ? (float) $ofertaMap[$artId]
                    : null;

                $art['precio_original'] = $precioOriginal;
                $art['precio_oferta']   = $precioOferta;
                $art['en_oferta']       = true;

                return $art;
            }, $filtered);

            $total = count($filtered);

            return [
                'content'       => $filtered,
                'totalElements' => $total,
                'totalPages'    => $total > 0 ? 1 : 0,
                'number'        => 0,
                'size'          => $total,
            ];
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener artículos en oferta', [
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudo conectar con el servicio de Paljet'];
        }
    }

    public function obtenerDomicilioIdDefault(int $paljetCliId): ?int
{
    try {
        Log::info("Paljet - Consultando cliente para domicilio", [
            'cliente_id' => $paljetCliId
        ]);

        $response = $this->client()->get("{$this->baseUrl}/clientes/{$paljetCliId}");

        Log::info("Paljet - Respuesta cliente domicilio", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        $domicilios = $data['domicilios'] ?? [];

        Log::info("Paljet - Domicilios encontrados", [
            'domicilios' => $domicilios
        ]);

        foreach ($domicilios as $dom) {
            if (($dom['por_defecto'] ?? '') === 'S'
                || ($dom['por_defecto'] ?? false) === true) {
                return (int) $dom['dom_id'];
            }
        }

        return !empty($domicilios) ? (int) $domicilios[0]['dom_id'] : null;

    } catch (\Throwable $e) {
        Log::error("Paljet - Excepción obteniendo domicilio", [
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

public function obtenerCondVtaIdDefault(int $paljetCliId): int
{
    try {
        Log::info("Paljet - Consultando cliente para condición venta", [
            'cliente_id' => $paljetCliId
        ]);

        $response = $this->client()->get("{$this->baseUrl}/clientes/{$paljetCliId}");

        Log::info("Paljet - Respuesta cliente condición venta", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) return 1;

        $data = $response->json();
        $condiciones = $data['condiciones_venta'] ?? [];

        Log::info("Paljet - Condiciones encontradas", [
            'condiciones' => $condiciones
        ]);

        foreach ($condiciones as $cond) {
            if (($cond['por_defecto'] ?? '') === 'S'
                || ($cond['por_defecto'] ?? false) === true) {
                return (int) $cond['id_cond'];
            }
        }

        return !empty($condiciones)
            ? (int) $condiciones[0]['id_cond']
            : 1;

    } catch (\Throwable $e) {
        Log::error("Paljet - Excepción obteniendo condición venta", [
            'error' => $e->getMessage()
        ]);
        return 1;
    }
}
}
