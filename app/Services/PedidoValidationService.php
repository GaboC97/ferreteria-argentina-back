<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PedidoValidationService
{
    public function __construct(protected PaljetService $paljet) {}

    /**
     * Verifica que el email de un invitado no esté ya registrado.
     *
     * Retorna null si está ok, o ['httpStatus' => int, 'payload' => array] en caso de error.
     */
    public function checkEmailRegistrado(string $email): ?array
    {
        $existe = DB::table('clientes')->where('email', $email)->exists()
               || DB::table('users')->where('email', $email)->exists();

        if (!$existe) {
            return null;
        }

        return [
            'httpStatus' => 422,
            'payload'    => [
                'error'   => 'email_registrado',
                'message' => 'Este email ya está registrado. Por favor, inicia sesión para continuar con tu compra.',
                'email'   => $email,
            ],
        ];
    }

    /**
     * Valida stock y precios de los items de Paljet contra el ERP.
     * Aplica precio_oferta local cuando corresponde.
     *
     * Retorna:
     *  - ['ok' => true,  'items' => Collection]  en caso de éxito (items con precios actualizados)
     *  - ['ok' => false, 'httpStatus' => int, 'payload' => array]  en caso de error
     */
    public function validarItemsPaljet(Collection $itemsPaljet, Collection $items): array
    {
        // Construir mapa de info Paljet [art_id => ['pr_final', 'admin_existencia', 'disponible']]
        $paljetInfoMap = [];

        foreach ($itemsPaljet->pluck('paljet_art_id')->unique() as $artId) {
            $info = $this->paljet->validarArticuloParaPedido((int) $artId);
            if ($info !== null) {
                $paljetInfoMap[(int) $artId] = $info;
            } else {
                Log::warning('Paljet - no se pudo verificar artículo al crear pedido', ['art_id' => $artId]);
            }
        }

        // 1) Validar stock por item
        foreach ($itemsPaljet as $item) {
            $artId = (int) $item['paljet_art_id'];
            $info  = $paljetInfoMap[$artId] ?? null;

            if ($info === null) {
                Log::warning('No se pudo validar stock con Paljet', ['art_id' => $artId]);
                return [
                    'ok'         => false,
                    'httpStatus' => 503,
                    'payload'    => [
                        'error'   => 'validacion_temporal',
                        'message' => 'Estamos actualizando disponibilidad de productos. Intentá nuevamente en unos segundos.',
                    ],
                ];
            }

            if (
                isset($info['admin_existencia']) &&
                $info['admin_existencia'] === true &&
                (int) $info['disponible'] < (int) $item['cantidad']
            ) {
                return [
                    'ok'         => false,
                    'httpStatus' => 409,
                    'payload'    => [
                        'error'   => 'sin_stock',
                        'message' => 'Sin stock disponible para: ' . ($item['nombre_producto'] ?? "artículo {$artId}"),
                    ],
                ];
            }
        }

        // 2) Actualizar precios: precio_oferta local tiene prioridad sobre pr_final de Paljet
        $ofertaPrecioMap = DB::table('paljet_ofertas')
            ->whereIn('paljet_art_id', $itemsPaljet->pluck('paljet_art_id')->unique()->values()->all())
            ->pluck('precio_oferta', 'paljet_art_id')
            ->toArray();

        $itemsArr = $items->all();

        foreach ($itemsArr as &$item) {
            if (empty($item['paljet_art_id'])) {
                continue;
            }

            $artId        = (int) $item['paljet_art_id'];
            $info         = $paljetInfoMap[$artId] ?? null;
            $precioOferta = isset($ofertaPrecioMap[$artId]) && (float) $ofertaPrecioMap[$artId] > 0
                ? (float) $ofertaPrecioMap[$artId]
                : null;

            if ($precioOferta !== null) {
                $item['precio_unitario'] = $precioOferta;
            } elseif ($info !== null && $info['pr_final'] !== null) {
                $item['precio_unitario'] = $info['pr_final'];
            } else {
                Log::warning('Precio no validado al crear pedido', ['art_id' => $artId, 'item' => $item]);
                return [
                    'ok'         => false,
                    'httpStatus' => 409,
                    'payload'    => [
                        'error'   => 'precio_no_validado',
                        'message' => 'Uno o más productos cambiaron de precio. Actualizá el carrito antes de continuar.',
                        'action'  => 'refresh_cart',
                    ],
                ];
            }
        }

        unset($item);

        return ['ok' => true, 'items' => collect($itemsArr)];
    }
}
