<?php

namespace App\Services;

use App\Models\CatalogoWeb;
use App\Models\PaljetArticuloOculto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Obtener artículos del catálogo para el frontend.
     * Lee desde la tabla local catalogo_web (sincronizada por catalogo:sync).
     * Fallback al ERP si la tabla está vacía.
     */
    public function getArticulos(array $filtros = []): array
    {
        $page = (int) ($filtros['page'] ?? 0);
        $size = (int) ($filtros['size'] ?? 20);

        // Soporta un único ID o lista separada por comas: "5" o "5,12,34"
        $categoriaIds = null;
        if (!empty($filtros['categoria'])) {
            $categoriaIds = array_values(array_filter(
                array_map('intval', explode(',', (string) $filtros['categoria']))
            ));
            if (empty($categoriaIds)) {
                $categoriaIds = null;
            }
        }

        try {
            // Si la tabla local tiene datos, servir desde DB
            return $this->getArticulosFromDB($filtros, $page, $size, $categoriaIds);

            if ($categoriaIds !== null) {
                $allDescendants = [];
                foreach ($categoriaIds as $catId) {
                    $allDescendants = array_merge($allDescendants, $this->getCategoryDescendantIds($catId));
                }
                $allDescendants = array_unique($allDescendants);
                $filtered = array_values(array_filter(
                    $filtered,
                    fn($a) => isset($a['categoria']['id']) && in_array($a['categoria']['id'], $allDescendants)
                ));
            }

            $total   = count($filtered);
            $content = array_slice($filtered, $page * $size, $size);

            return [
                'content'       => array_values($content),
                'totalElements' => $total,
                'totalPages'    => (int) ceil($total / max($size, 1)),
                'number'        => $page,
                'size'          => $size,
            ];
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener artículos', [
                'message' => $e->getMessage(),
            ]);
            return ['error' => 'No se pudieron cargar los productos. Intentá de nuevo en unos instantes.'];
        }
    }

    /**
     * Consulta SQL sobre catalogo_web con filtros, multi-marca, ofertas y sorting.
     */
    private function getArticulosFromDB(array $filtros, int $page, int $size, ?array $categoriaIds): array
    {
        $query = CatalogoWeb::query()
            ->from('catalogo_web')
            ->leftJoin('paljet_ofertas', 'catalogo_web.paljet_art_id', '=', 'paljet_ofertas.paljet_art_id')
            ->where('catalogo_web.precio', '>', 0);

        if (!empty($filtros['descripcion'])) {
            $query->where('catalogo_web.descripcion', 'like', '%' . $filtros['descripcion'] . '%');
        }
        if (!empty($filtros['desc_mod_med'])) {
            $query->where('catalogo_web.desc_mod_med', 'like', '%' . $filtros['desc_mod_med'] . '%');
        }
        if (!empty($filtros['codigo'])) {
            $query->where('catalogo_web.codigo', $filtros['codigo']);
        }
        if (!empty($filtros['ean'])) {
            $query->where('catalogo_web.ean', $filtros['ean']);
        }

        // Multi-marca: array de nombres exactos
        if (!empty($filtros['marcas']) && is_array($filtros['marcas'])) {
            $query->whereIn('catalogo_web.marca_nombre', $filtros['marcas']);
        }

        if (!empty($filtros['familia'])) {
            $query->where('catalogo_web.familia_nombre', 'like', '%' . $filtros['familia'] . '%');
        }

        // Filtro en oferta
        if (!empty($filtros['en_oferta'])) {
            $query->whereNotNull('paljet_ofertas.paljet_art_id');
        }

        if ($categoriaIds !== null) {
            $allDescendants = [];
            foreach ($categoriaIds as $catId) {
                $allDescendants = array_merge($allDescendants, $this->getCategoryDescendantIds($catId));
            }
            $query->whereIn('catalogo_web.categoria_id', array_unique($allDescendants));
        }

        // Sorting
        $sort = $filtros['sort'] ?? 'relevancia';
        match ($sort) {
            'precio_asc'  => $query->orderBy('catalogo_web.precio', 'asc'),
            'precio_desc' => $query->orderBy('catalogo_web.precio', 'desc'),
            'oferta'      => $query->orderByRaw('paljet_ofertas.paljet_art_id IS NOT NULL DESC'),
            default       => $query->orderBy('catalogo_web.ventas_count', 'desc')
                ->orderBy('catalogo_web.paljet_art_id', 'asc'),
        };

        $cols = [
            'catalogo_web.paljet_art_id',
            'catalogo_web.codigo',
            'catalogo_web.ean',
            'catalogo_web.descripcion',
            'catalogo_web.desc_cliente',
            'catalogo_web.desc_mod_med',
            'catalogo_web.marca_id',
            'catalogo_web.marca_nombre',
            'catalogo_web.familia_id',
            'catalogo_web.familia_nombre',
            'catalogo_web.categoria_id',
            'catalogo_web.categoria_nombre',
            'catalogo_web.precio',
            'catalogo_web.admin_existencia',
            'catalogo_web.stock',
            'catalogo_web.ultimas_unidades',
            'catalogo_web.imagen_url',
            'catalogo_web.tiene_imagen',
            'catalogo_web.listas_json',
            'paljet_ofertas.precio_oferta',
        ];

$items = $query->paginate($size, $cols, 'page', $page + 1);

$content = collect($items->items())
    ->map(fn($row) => $this->catalogoWebToArray($row))
    ->values()
    ->all();

return [
    'content'       => $content,
    'totalElements' => $items->total(),
    'totalPages'    => $items->lastPage(),
    'number'        => $items->currentPage() - 1,
    'size'          => $items->perPage(),
];
    }

    /**
     * Convierte un registro de catalogo_web al formato que usa el frontend.
     */
    private function catalogoWebToArray($row): array
    {
        $precioOriginal = (float) $row->precio;
        $precioOferta   = isset($row->precio_oferta) && $row->precio_oferta > 0
            ? (float) $row->precio_oferta
            : null;
        $enOferta = $precioOferta !== null;

        return [
            'id'               => (int) $row->paljet_art_id,
            'art_id'           => (int) $row->paljet_art_id,
            'codigo'           => $row->codigo,
            'ean'              => $row->ean,
            'descripcion'      => $row->descripcion,
            'nombre'           => $row->descripcion,
            'desc_cliente'     => $row->desc_cliente,
            'desc_mod_med'     => $row->desc_mod_med,
            'marca'            => $row->marca_id
                ? ['id' => (int) $row->marca_id, 'nombre' => $row->marca_nombre]
                : ['id' => null, 'nombre' => $row->marca_nombre],
            'familia'          => $row->familia_id
                ? ['id' => (int) $row->familia_id, 'nombre' => $row->familia_nombre]
                : null,
            'categoria'        => $row->categoria_id
                ? ['id' => (int) $row->categoria_id, 'nombre' => $row->categoria_nombre]
                : null,
            'precio'           => $precioOriginal,
            'precio_original'  => $precioOriginal,
            'precio_oferta'    => $precioOferta,
            'en_oferta'        => $enOferta,
            'listas'           => is_string($row->listas_json)
                ? json_decode($row->listas_json, true) ?? []
                : ($row->listas_json ?? []),
            'admin_existencia' => (bool) $row->admin_existencia,
            'stock_disponible' => $row->admin_existencia ? (int) $row->stock : null,
            'stock'            => (int) $row->stock,
            'ultimas_unidades' => (bool) $row->ultimas_unidades,
            'imagen_url'       => $row->tiene_imagen === false ? null : $row->imagen_url,
        ];
    }

    /**
     * Devuelve el catálogo completo enriquecido y filtrado desde caché.
     * Si la caché expiró, lo recarga desde Paljet.
     */
    public function getCachedFullCatalog(): array
    {
        $ttl      = (int) config('services.paljet.cache_ttl', 600);
        $cacheKey = "paljet_catalogo_dep_{$this->depId}";

        return Cache::remember($cacheKey, $ttl, fn() => $this->fetchFullCatalogFromERP());
    }

    /**
     * Fuerza la recarga del catálogo desde Paljet, actualiza la caché e invalida categorías.
     */
    public function warmupCatalogCache(): array
    {
        $cacheKey = "paljet_catalogo_dep_{$this->depId}";
        $catalog  = $this->fetchFullCatalogFromERP();
        $ttl      = (int) config('services.paljet.cache_ttl', 600);

        Cache::put($cacheKey, $catalog, $ttl);
        Cache::forget("paljet_categorias_{$this->depId}");

        return $catalog;
    }

    /**
     * Pagina todos los artículos del ERP, los enriquece con stock e imagen,
     * filtra los no publicables y los ocultos, y devuelve el array completo.
     */
    private function fetchFullCatalogFromERP(): array
    {
        set_time_limit(300); // La descarga completa puede tardar más de 60s

        $pageSize     = 1000;
        $allArticulos = [];
        $page         = 0;

        do {
            $response = $this->client()->get("{$this->baseUrl}/articulos", [
                'dep_id'       => $this->depId,
                'solo_activos' => 'true',
                'include'      => 'listas',
                'size'         => $pageSize,
                'page'         => $page,
            ]);

            if (!$response->successful()) {
                Log::error('Paljet WS - Error al paginar catálogo completo', [
                    'page'   => $page,
                    'status' => $response->status(),
                ]);
                break;
            }

            $data         = $response->json();
            $allArticulos = array_merge($allArticulos, $data['content'] ?? []);
            $totalPages   = (int) ($data['totalPages'] ?? 1);
            $page++;
        } while ($page < $totalPages);

        Log::info('Paljet WS - Catálogo descargado', ['total_raw' => count($allArticulos)]);

        $stockMap   = $this->getStockMapDeposito();
        $ocultosSet = PaljetArticuloOculto::pluck('paljet_art_id')->flip()->all();

        $enriched = [];
        foreach ($allArticulos as $art) {
            if (isset($ocultosSet[(int) $art['id']])) {
                continue;
            }
            $art = $this->enrichArticulo($art, $stockMap[(int) $art['id']] ?? null);
            if ($this->articuloPublicable($art)) {
                $enriched[] = $this->slimArticulo($art);
            }
        }

        Log::info('Paljet WS - Catálogo listo para caché', ['total_publicables' => count($enriched)]);

        return $enriched;
    }

    /**
     * Aplica filtros de texto y campo sobre el array completo del catálogo cacheado.
     */
    private function applyFiltros(array $articulos, array $filtros): array
    {
        return array_values(array_filter($articulos, function ($art) use ($filtros) {
            if (!empty($filtros['descripcion'])) {
                if (stripos($art['descripcion'] ?? '', $filtros['descripcion']) === false) {
                    return false;
                }
            }

            if (!empty($filtros['desc_mod_med'])) {
                if (stripos($art['desc_mod_med'] ?? '', $filtros['desc_mod_med']) === false) {
                    return false;
                }
            }

            if (!empty($filtros['codigo'])) {
                if (($art['codigo'] ?? '') !== $filtros['codigo']) {
                    return false;
                }
            }

            if (!empty($filtros['ean'])) {
                if (($art['ean'] ?? '') !== $filtros['ean']) {
                    return false;
                }
            }

            if (!empty($filtros['marca'])) {
                $marcaNombre = is_array($art['marca'] ?? null)
                    ? ($art['marca']['nombre'] ?? '')
                    : ($art['marca'] ?? '');
                if (stripos($marcaNombre, $filtros['marca']) === false) {
                    return false;
                }
            }

            if (!empty($filtros['familia'])) {
                $familiaNombre = is_array($art['familia'] ?? null)
                    ? ($art['familia']['nombre'] ?? '')
                    : ($art['familia'] ?? '');
                if (stripos($familiaNombre, $filtros['familia']) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Devuelve el ID de la categoría dada y todos sus descendientes.
     * Necesario para filtrar artículos por categoría padre.
     */
    protected function getCategoryDescendantIds(int $categoryId): array
    {
        try {
            $ttl      = (int) config('services.paljet.cache_ttl', 600);
            $cacheKey = "paljet_categorias_tree_{$this->empId}";

            $tree = Cache::remember($cacheKey, $ttl, function () {
                $response = $this->client()->get("{$this->baseUrl}/categorias");
                return $response->successful() ? $response->json() : null;
            });

            if (!$tree) {
                return [$categoryId];
            }

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
        $path = public_path("productos/{$codigo}.jpg");

        // Si la imagen ya existe en disco
        if (file_exists($path)) {
            return [
                'body' => file_get_contents($path),
                'type' => 'image/jpeg'
            ];
        }

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

            // Crear carpeta si no existe
            $dir = storage_path("app/public/productos");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            // Guardar imagen en disco
            file_put_contents($path, $body);

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
     * Lista de marcas con conteo de artículos en el catálogo activo.
     * Sirve directo desde DB — reemplaza el request ?size=500 del frontend.
     */
    public function getMarcas(): array
    {
        return CatalogoWeb::where('precio', '>', 0)
            ->whereNotNull('marca_nombre')
            ->where('marca_nombre', '!=', '')
            ->selectRaw('marca_nombre as nombre, COUNT(*) as count')
            ->groupBy('marca_nombre')
            ->orderBy('marca_nombre')
            ->get()
            ->toArray();
    }

    /**
     * Árbol de categorías cacheado.
     * Si la tabla local existe, lee categorías activas desde DB (sin llamar al ERP).
     */
    public function getCategorias(): array
    {
        $ttl      = (int) config('services.paljet.cache_ttl', 600);
        $cacheKey = "paljet_categorias_{$this->depId}";

        return Cache::remember($cacheKey, $ttl, function () {
            // IDs de categorías con artículos activos
            if (CatalogoWeb::query()->count() > 0) {
                $activeCatIds = CatalogoWeb::where('precio', '>', 0)
                    ->whereNotNull('categoria_id')
                    ->pluck('categoria_id')
                    ->unique()
                    ->flip()
                    ->all();
            } else {
                $articulos    = $this->getCachedFullCatalog();
                $activeCatIds = [];
                foreach ($articulos as $art) {
                    $cat = $art['categoria'] ?? null;
                    if (isset($cat['id'])) {
                        $activeCatIds[$cat['id']] = true;
                    }
                }
            }

            // Árbol completo desde Paljet (solo estructura de árbol, no artículos)
            $catResponse = $this->client()->get("{$this->baseUrl}/categorias");

            if (!$catResponse->successful()) {
                return ['error' => 'Error al obtener categorías', 'status' => $catResponse->status()];
            }

            $tree      = $catResponse->json();
            $rootHijos = (is_array($tree) && isset($tree[0]['hijos'])) ? $tree[0]['hijos'] : [];

            return $this->pruneCategoriasTree($rootHijos, $activeCatIds);
        });
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
     * Obtiene los datos completos de un cliente de Paljet por su cli_id.
     * Retorna el array de datos o null si falla.
     */
    public function getClientePaljet(int $cliId): ?array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/clientes/{$cliId}");

            if (!$response->successful()) {
                Log::warning('Paljet WS - No se pudo obtener datos del cliente', [
                    'cli_id' => $cliId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Paljet WS - Excepción al obtener datos de cliente', [
                'cli_id'  => $cliId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Busca un cliente en Paljet por CUIT o DNI.
     * Retorna el cli_id de Paljet o null si no se encuentra.
     */
    public function buscarClientePorCuitODni(?string $cuit = null, ?string $dni = null): ?int
    {
        // Normalizar: quitar todo lo que no sea número
        $cuitLimpio = $cuit ? preg_replace('/[^0-9]/', '', $cuit) : null;

        // Solo buscar si es un CUIT válido (11 dígitos)
        if ($cuitLimpio && strlen($cuitLimpio) === 11) {

            $id = $this->buscarClienteEnPaljet('cuit', $cuitLimpio);

            if ($id !== null) {
                return $id;
            }
        }

        // No se encontró cliente (o no había CUIT válido)
        Log::warning('Paljet WS - Cliente no encontrado por CUIT', [
            'cuit' => $cuitLimpio,
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
        Log::error('🔥 VERSION NUEVA CREAR CLIENTE ACTIVA 🔥');
        // =========================
        // 1. Normalizar CUIT
        // =========================
        $cuitRaw    = preg_replace('/[^0-9]/', '', $datos['cuit'] ?? '');
        $cuitLimpio = strlen($cuitRaw) === 11 ? $cuitRaw : null;

        if (!$cuitLimpio) {
            Log::error('Paljet WS - Intento de crear cliente sin CUIT válido', [
                'cuit_recibido' => $datos['cuit'] ?? null
            ]);
            return null;
        }

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
        $prefijo = substr($cuitLimpio, 0, 2);

        if ($prefijo === '27') {
            $sexo = 'F';
        } elseif (in_array($prefijo, ['30', '33', '34'])) {
            $sexo = 'E';
        }

        // =========================
        // 4. Construcción del Body
        // =========================

        // Nombre completo en mayúsculas. Paljet sobreescribe rz internamente (AFIP lookup),
        // pero nom_fantasia sí se guarda como lo enviamos. Mandamos ambos con el mismo valor.
        $nombre      = strtoupper(trim($datos['nombre'] ?? ''));
        $apellido    = strtoupper(trim($datos['apellido'] ?? ''));
        $nombreCompleto = trim("$apellido $nombre") ?: 'CLIENTE WEB';

        $body = [
            'cod_cli'          => '',
            'cli_tipo_id'      => 1,
            'rz'               => $nombreCompleto,
            'nom_fantasia'     => $nombreCompleto,
            'cuit'             => $cuitLimpio,
            'iva_id'           => $ivaId,
            'sexo'             => $sexo,
            'crediticio'       => false,
            'ctacorrentista'   => false,
            'ctacte_tipo_id'   => 1,
            'discrimina_bonif' => false,
            'es_cli_generico'  => false,
            'copia_nota_cpr'   => false,
            'muestra_nota_cpr' => false,
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
        }

        // =========================
        // 7. Domicilio (OBLIGATORIO PARA FACTURAR)
        // =========================
        $locId = (int) config('services.paljet.web_loc_id', 174);

        $calle    = 'WEB';
        $calleNro = 0;
        $cpNuevo  = '';
        $domText  = 'WEB 0';

        if (!empty($datos['direccion'])) {
            // Separar "Ceferino Namuncura 1313" → calle + número
            if (preg_match('/^(.+?)\s+(\d+)\s*$/', trim($datos['direccion']), $m)) {
                $calle    = strtoupper(trim($m[1]));
                $calleNro = (int) $m[2];
            } else {
                $calle    = strtoupper(trim($datos['direccion']));
                $calleNro = 0;
            }
            $cpNuevo = strtoupper($datos['codigo_postal'] ?? '');
            $domText = strtoupper(trim($datos['direccion']));
        }

        $body['domicilios'] = [[
            'calle'         => $calle,
            'calle_nro'     => $calleNro,
            'cp_nuevo'      => $cpNuevo,
            'dom'           => $domText,
            'dom_clasif_id' => 'DP',
            'loc_id'        => $locId,
            'por_defecto'   => true,
            'entre_calle'   => '',
            'partido'       => strtoupper($datos['localidad'] ?? ''),
            'latitud'       => '',
            'longitud'      => '',
            'local'         => 0,
        ]];

        // =========================
        // 8. Enviar a Paljet
        // =========================
        Log::info('Paljet WS - Body crearCliente', ['rz' => $body['rz'], 'nom_fantasia' => $body['nom_fantasia'], 'datos_recibidos' => array_diff_key($datos, ['password' => true])]);
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

            $newId = null;

            if (isset($data['cli_id'])) {
                $newId = $data['cli_id'];
            } elseif (isset($data['cliId'])) {
                $newId = $data['cliId'];
            } elseif (isset($data['id'])) {
                $newId = $data['id'];
            } elseif (isset($data['_embedded']['clienteResources'][0]['cliId'])) {
                $newId = $data['_embedded']['clienteResources'][0]['cliId'];
            }

            if ($newId) {
                Log::info('Paljet WS - Cliente creado correctamente', [
                    'paljet_id' => $newId,
                    'cuit'      => $cuitLimpio,
                ]);
                return (int) $newId;
            }

            Log::error('Paljet WS - Cliente creado pero ID no detectado', [
                'response' => $data
            ]);

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
                'size'  => 1,
                'page'  => 0,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $json = $response->json();

            $clientes = $json['_embedded']['clienteResources'] ?? [];

            if (empty($clientes)) {
                return null;
            }

            $cliente = $clientes[0];

            return isset($cliente['cliId'])
                ? (int) $cliente['cliId']
                : null;
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
     * @return array|null ['cpr_id' => int, 'pto_vta' => int|null, 'numero' => int|null, 'letra' => string|null]
     */
    public function enviarPedidoWeb(
        int $paljetCliId,
        array $items,
        int $domicilioId,
        string $nota = null,
        int $condVtaId = null
    ): ?array {

        if (empty($items)) {
            Log::warning('Paljet WS - Intento de enviar pedido sin items', [
                'cliente' => $paljetCliId
            ]);
            return null;
        }

        $body = [
            'aplica_dto_nivel' => true,
            'cliente'          => $paljetCliId,
            'cond_vta_id'      => $condVtaId ?? (int) config('services.paljet.web_cond_vta', 1),
            'domicilio'        => $domicilioId,
            'loc_id'           => (int) config('services.paljet.web_loc_id', 174),
            'detalle'          => array_map(function ($i) {
                return [
                    'articulo'  => (int) $i['art_id'],
                    'cantidad'  => (float) $i['cantidad'],
                    'cantBonif' => 0,
                    'listaId'   => 0,
                ];
            }, $items),
        ];

        // No enviamos mailDestinatarios a Paljet — los mails los manejamos nosotros

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

            // ✅ CAPTURA REAL DEL CPR_ID
            $cprId = data_get($data, 'pedido.cpr_id')
                ?? data_get($data, 'cpr_id')
                ?? data_get($data, 'pedido.id')
                ?? data_get($data, 'id')
                ?? data_get($data, 'pedido_id')
                ?? data_get($data, 'ped_id');

            if ($cprId) {

                // ⚠️ Si viene bloque "error" es por el mail, NO por el pedido
                if (isset($data['error'])) {
                    Log::warning('Paljet WS - Pedido emitido pero falló el mail', [
                        'cpr_id'    => $cprId,
                        'message'   => data_get($data, 'error.message'),
                        'developer' => data_get($data, 'error.developerMesasge'),
                    ]);
                }

                $ptoVta = data_get($data, 'pedido.pto_vta');
                $numero = data_get($data, 'pedido.numero');
                $letra  = data_get($data, 'pedido.letra');

                Log::info('Paljet WS - Pedido creado correctamente', [
                    'cpr_id'  => $cprId,
                    'pto_vta' => $ptoVta,
                    'numero'  => $numero,
                    'letra'   => $letra,
                ]);

                return [
                    'cpr_id'  => (int) $cprId,
                    'pto_vta' => $ptoVta,
                    'numero'  => $numero,
                    'letra'   => $letra,
                ];
            }

            Log::warning('Paljet WS - Respuesta 200 pero sin cpr_id', [
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
        // 🔒 IDEMPOTENCIA
        // =========================

        if (!empty($pedido->paljet_pedido_id)) {
            Log::info("Paljet - Pedido ya generado previamente", [
                'pedido_id' => $pedido->id,
                'paljet_pedido_id' => $pedido->paljet_pedido_id
            ]);

            return (int) $pedido->paljet_pedido_id;
        }


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
        Log::info('DEBUG PEDIDO PARA PALJET', [
            'pedido_id' => $pedido->id,
            'cuit_contacto' => $pedido->cuit_contacto,
            'condicion_iva' => $pedido->condicion_iva_contacto,
            'email' => $pedido->email_contacto,
        ]);
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

        // 🔎 Detectar si el pedido fue pagado con MercadoPago
        $medioMp = DB::table('medios_pago')
            ->where('codigo', 'mercadopago')
            ->first();

        $pagoMp = null;

        if ($medioMp) {
            $pagoMp = \App\Models\Pago::where('pedido_id', $pedido->id)
                ->where('medio_pago_id', $medioMp->id)
                ->where('estado', 'aprobado')
                ->first();
        }

        if (!$pedido->medio_pago_id) {
            throw new \Exception("Pedido {$pedido->id} sin medio_pago_id definido.");
        }

        $medioPagoCodigo = DB::table('medios_pago')
            ->where('id', $pedido->medio_pago_id)
            ->value('codigo');

        if (!$medioPagoCodigo) {
            throw new \Exception("medio_pago_id {$pedido->medio_pago_id} no válido.");
        }

        $paljetCondVtaId = $this->resolverCondicionVenta($medioPagoCodigo);

        Log::info("Paljet - Condición venta seleccionada", [
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

            $paljetResult = $this->enviarPedidoWeb(
                $paljetCliId,
                $paljetItems,
                $paljetDomId,
                $pedido->nota_cliente ?: null,
                $paljetCondVtaId
            );

            Log::info("Paljet - Resultado enviarPedidoWeb", [
                'pedido_id' => $pedido->id,
                'resultado' => $paljetResult
            ]);

            // 🔥 Si Paljet devuelve null o sin cpr_id → explotar
            $paljetPedidoId = $paljetResult['cpr_id'] ?? null;

            if (empty($paljetPedidoId) || !is_numeric($paljetPedidoId)) {

                Log::error("Paljet - Pedido rechazado por API", [
                    'pedido_id' => $pedido->id,
                    'respuesta' => $paljetResult
                ]);

                throw new \Exception("Paljet rechazó el pedido {$pedido->id}");
            }

            // Formatear número de comprobante con pto_vta y numero de Paljet
            $ptoVta = $paljetResult['pto_vta'] ?? null;
            $numero = $paljetResult['numero']  ?? null;
            $numeroComprobante = ($ptoVta !== null && $numero !== null)
                ? str_pad((int) $ptoVta, 4, '0', STR_PAD_LEFT) . '-' . str_pad((int) $numero, 6, '0', STR_PAD_LEFT)
                : null;

            // Guardar ID ERP y número de comprobante
            $pedido->update([
                'paljet_pedido_id'   => (int) $paljetPedidoId,
                'numero_comprobante' => $numeroComprobante,
            ]);

            Log::info("Paljet - Pedido web enviado OK", [
                'pedido_id'          => $pedido->id,
                'paljet_pedido_id'   => $paljetPedidoId,
                'numero_comprobante' => $numeroComprobante,
            ]);

            return (int) $paljetPedidoId;
        } catch (\Throwable $e) {

            Log::error("Paljet - Excepción crítica al enviar pedido", [
                'pedido_id' => $pedido->id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);

            // 🔥 MUY IMPORTANTE:
            // NO devolver null
            // NO silenciar
            // Dejar que el error suba al webhook

            throw $e;
        }
    }


    private function resolverCondicionVenta(string $medioPago): int
    {
        $map = config('services.paljet.condiciones');

        // Normalizar entrada (clave para evitar bugs)
        $medioPago = strtolower(trim($medioPago));

        if (!isset($map[$medioPago])) {
            throw new \Exception("Medio de pago no mapeado en config: {$medioPago}");
        }

        $condicionId = (int) $map[$medioPago];

        if (!$condicionId) {
            throw new \Exception("Condición de venta inválida para medio: {$medioPago}");
        }

        return $condicionId;
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
            return ['error' => 'No se pudo cargar el producto. Intentá de nuevo en unos instantes.'];
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
    /**
     * Determina si un artículo es publicable en la web.
     * Requisitos: stock > 0 (si Paljet gestiona existencia) y precio final > 0.
     */
    private function articuloPublicable(array $articulo): bool
    {
        // Solo se filtra por precio: debe tener precio final > 0 en alguna lista de precios.
        // Los artículos sin stock se muestran igual (el frontend los marca como "agotado").
        $precio = 0.0;
        foreach ($articulo['listas'] ?? [] as $lista) {
            if (isset($lista['pr_final']) && (float) $lista['pr_final'] > 0) {
                $precio = (float) $lista['pr_final'];
                break;
            }
        }
        if ($precio === 0.0 && isset($articulo['pr_final'])) {
            $precio = (float) $articulo['pr_final'];
        }

        return $precio > 0.0;
    }

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
     * Reduce un artículo enriquecido a solo los campos necesarios para el frontend.
     * Elimina campos internos del ERP y slim-down de listas para reducir el tamaño del caché.
     */
    private function slimArticulo(array $art): array
    {
        // Solo slim-down del array listas: quedarse con los campos esenciales para precio.
        // El resto de campos del artículo se mantienen para no romper el frontend.
        $listas = [];
        foreach ($art['listas'] ?? [] as $lista) {
            $listas[] = array_intersect_key($lista, array_flip([
                'lista',
                'lista_id',
                'lista_nombre',
                'pr_final',
                'pr_vta',
                'nombre',
            ]));
        }

        $art['listas'] = $listas;
        return $art;
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
                return ['error' => 'No se pudieron cargar los productos. Intentá de nuevo en unos instantes.', 'status' => $response->status()];
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
                    'stock_disponible' => 0,
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
            return ['error' => 'No se pudieron cargar los productos. Intentá de nuevo en unos instantes.'];
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
            $map      = [];
            $page     = 0;
            $pageSize = 1000;

            do {
                $response = $this->client()->get("{$this->baseUrl}/stock", [
                    'depositos' => $this->depId,
                    'size'      => $pageSize,
                    'page'      => $page,
                ]);

                if (!$response->successful()) {
                    break;
                }

                $data       = $response->json();
                $entries    = $data['content'] ?? [];
                $totalPages = (int) ($data['totalPages'] ?? 1);

                foreach ($entries as $entry) {
                    $artId = $entry['articulo']['art_id'] ?? null;
                    if ($artId !== null) {
                        $map[(int) $artId] = (float) ($entry['disponible'] ?? 0);
                    }
                }

                $page++;
            } while ($page < $totalPages);

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
                return ['error' => 'No se pudieron cargar los productos. Intentá de nuevo en unos instantes.', 'status' => $response->status()];
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
            return ['error' => 'No se pudieron cargar los productos. Intentá de nuevo en unos instantes.'];
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
                    || ($dom['por_defecto'] ?? false) === true
                ) {
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
                    || ($cond['por_defecto'] ?? false) === true
                ) {
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

    /**
     * Registra un pedido confirmado en Paljet (fuera de la transacción de pago).
     *
     * Soft-falla ante cualquier error: loguea y retorna null en lugar de lanzar.
     * Si tiene éxito, guarda paljet_pedido_id en la tabla pedidos y retorna el ID.
     *
     * @return int|null  ID del pedido en Paljet, o null si no se pudo registrar.
     */
    public function registrarPedidoConfirmado(int $pedidoId): ?int
    {
        $pedido = \App\Models\Pedido::find($pedidoId);

        if (!$pedido) {
            Log::error("Pedido #{$pedidoId} no existe.");
            return null;
        }

        return $this->generarFacturaDePedido($pedido);
    }
}
