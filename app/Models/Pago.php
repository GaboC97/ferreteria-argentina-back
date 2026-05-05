<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'pedido_id',
        'medio_pago_id',
        'estado',
        'monto',
        'moneda',
        // Getnet
        'getnet_payment_id',
        'getnet_order_id',
        'getnet_idempotency_key',
        'getnet_status',
        'getnet_raw_json',
        'getnet_checkout_url',
        // Refund (genérico)
        'refund_monto',
        'refund_status',
        'devuelto_en',
        // Timestamps de negocio
        'aprobado_en',
    ];

    protected $appends = ['motivo_rechazo_legible'];

    protected $casts = [
        'monto'           => 'decimal:2',
        'refund_monto'    => 'decimal:2',
        'getnet_raw_json' => 'array',
        'aprobado_en'     => 'datetime',
        'devuelto_en'     => 'datetime',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * Elimina del array de respuesta de Getnet cualquier key que pueda
     * contener datos de tarjeta o información sensible antes de persistir.
     * Getnet es PCI-compliant y no debería enviar estos datos, pero lo
     * garantizamos en nuestro lado por si cambia la respuesta en el futuro.
     */
    public static function sanitizeGetnetRaw(array $data): array
    {
        $sensibleKeys = [
            'card_number', 'pan', 'cvv', 'cvc', 'expiry', 'expiration',
            'cardholder_name', 'card_holder', 'track1', 'track2',
            'security_code', 'pin', 'password', 'secret',
        ];

        array_walk_recursive($data, function (&$value, $key) use ($sensibleKeys) {
            if (in_array(strtolower($key), $sensibleKeys, true)) {
                $value = '[REDACTED]';
            }
        });

        return $data;
    }

    protected function motivoRechazoLegible(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->estado !== 'rechazado') {
                    return null;
                }

                // Mapeo Getnet
                $map = [
                    'session_error'      => 'Error al iniciar la sesión de pago',
                    'payment_declined'   => 'Pago rechazado por el banco',
                    'insufficient_funds' => 'Fondos insuficientes',
                    'expired_card'       => 'Tarjeta vencida',
                    'invalid_card'       => 'Tarjeta inválida',
                ];

                if ($this->getnet_status) {
                    return $map[$this->getnet_status] ?? 'Pago rechazado por Getnet';
                }

                return null;
            }
        );
    }
}
