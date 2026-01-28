<?php

namespace App\Services;

use App\Models\Pedido;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaljetService
{
    protected $apiClient;
    protected $baseUri;

    public function __construct()
    {
        $this->baseUri = rtrim(config('services.paljet.base_uri'), '/');
        $apiKey = config('services.paljet.api_key');

        if (!$this->baseUri || !$apiKey) {
            throw new \Exception('Paljet base_uri o api_key no están configuradas en services.php');
        }

        $this->apiClient = Http::withHeaders([
            'PJET_API_KEY' => $apiKey,
        ])->acceptJson();
    }

    public function generarFacturaDePedido(Pedido $pedido): ?string
    {
        try {
            // Cargamos todas las relaciones necesarias
            $pedido->loadMissing(['cliente', 'items.producto']);

            $clientePaljetId = $this->obtenerOCrearClienteEnPaljet($pedido);

            // Decidir el tipo de comprobante basado en la condición de IVA
            $esRespInscripto = (str_contains(strtolower($pedido->condicion_iva_contacto ?? ''), 'responsable inscripto'));
            
            $cprTipoId = $esRespInscripto 
                ? config('services.paljet.cpr_tipo_factura_a') 
                : config('services.paljet.cpr_tipo_factura_b');

            if (!$cprTipoId) {
                throw new \Exception("El tipo de comprobante (Factura A o B) no está configurado para la condición de IVA: {$pedido->condicion_iva_contacto}");
            }

            $itemsFactura = [];
            foreach ($pedido->items as $item) {
                 if (empty($item->producto->paljet_id)) {
                    throw new \Exception("El producto local ID {$item->producto->id} no tiene un paljet_id asociado.");
                }
                $itemsFactura[] = [
                    'articulo_id' => $item->producto->paljet_id,
                    'cantidad' => $item->cantidad,
                    'precio' => (float) $item->precio_unitario, // Paljet requiere el precio unitario sin IVA
                ];
            }
            
            $payloadFactura = [
                'cliente_id' => $clientePaljetId,
                'cpr_tipo_id' => $cprTipoId,
                'talonario_id' => config('services.paljet.talonario_web_id'),
                'fecha_emision' => now()->format('Y-m-d'),
                'items' => $itemsFactura,
                'moneda_id' => 1, // ARS
            ];

            Log::info("Enviando a Paljet para crear factura", ['pedido_id' => $pedido->id, 'payload' => $payloadFactura]);

            $response = $this->apiClient->post("{$this->baseUri}/cpr-cliente", $payloadFactura);

            if (!$response->successful()) {
                throw new \Exception('Error al crear la factura en Paljet: ' . $response->body());
            }

            $facturaData = $response->json();
            $numeroFactura = data_get($facturaData, 'cpr_numero_completo');

            if ($numeroFactura) {
                $pedido->paljet_factura = $numeroFactura;
                $pedido->paljet_cpr_id = data_get($facturaData, 'id');
                $pedido->save();
                Log::info("Factura generada en Paljet para pedido {$pedido->id}: {$numeroFactura}");
            } else {
                 Log::warning("La respuesta de Paljet no incluyó un número de factura.", ['pedido_id' => $pedido->id, 'response' => $facturaData]);
            }

            return $numeroFactura;

        } catch (\Throwable $e) {
            Log::error("Error al generar factura en Paljet para pedido {$pedido->id}", [
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    private function obtenerOCrearClienteEnPaljet(Pedido $pedido): int
    {
        // 1. Buscar por email o CUIT si existe
        $identificadorBusqueda = $pedido->cuit_contacto ?: $pedido->cliente->email;
        $campoBusqueda = $pedido->cuit_contacto ? 'cuit' : 'email';

        $response = $this->apiClient->get("{$this->baseUri}/cliente", [
            $campoBusqueda => $identificadorBusqueda,
            'limit' => 1
        ]);
        
        if (!$response->successful()) {
            throw new \Exception("Error al buscar cliente en Paljet por {$campoBusqueda}: " . $response->body());
        }

        $clientes = $response->json();
        
        if (!empty($clientes['data'])) {
            return (int) $clientes['data'][0]['id'];
        }

        // 2. Si no existe, crearlo con todos los datos fiscales
        $domicilio = [];
        if ($pedido->tipo_entrega === 'envio') {
             $domicilio = [
                'tipo_domicilio_id' => 1, // "Legal"
                'direccion' => $pedido->envio->calle . ' ' . $pedido->envio->numero,
                'localidad' => $pedido->envio->ciudad,
                'provincia' => $pedido->envio->provincia,
                'cod_postal' => $pedido->envio->codigo_postal,
            ];
        }

        $payloadCliente = [
            'razon_social' => $pedido->nombre_contacto,
            'nombre_fantasia' => $pedido->nombre_contacto,
            'email' => $pedido->email_contacto,
            'cuit' => $pedido->cuit_contacto,
            'nro_doc' => $pedido->dni_contacto,
            'telefono' => $pedido->telefono_contacto,
            'cond_iva_id' => $this->mapCondicionIvaToPaljetId($pedido->condicion_iva_contacto),
            'lista_precios_id' => config('services.paljet.default_lista_precios_id'),
            'domicilios' => $domicilio ? [$domicilio] : [],
        ];

        Log::info("Creando cliente en Paljet", ['payload' => $payloadCliente]);

        $response = $this->apiClient->post("{$this->baseUri}/cliente", $payloadCliente);
        
        if (!$response->successful()) {
            throw new \Exception('Error al crear cliente en Paljet: ' . $response->body());
        }

        $nuevoCliente = $response->json();
        return (int) data_get($nuevoCliente, 'id');
    }

    private function mapCondicionIvaToPaljetId(string $condicionIva): int
    {
        $map = config('services.paljet.iva_map', []);
        $condicionLower = strtolower($condicionIva);

        // Busca una coincidencia parcial para más flexibilidad
        foreach ($map as $key => $id) {
            if (str_contains($condicionLower, $key)) {
                return $id;
            }
        }

        // Si no encuentra, devuelve el ID por defecto para Consumidor Final
        return $map['consumidor final'] ?? 4; 
    }
}
