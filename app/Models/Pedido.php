<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $fillable = [
        'cliente_id',
        'sucursal_id',
        'tipo_entrega',
        'nombre_contacto',
        'email_contacto',
        'telefono_contacto',
        'estado',
        'total_productos',
        'costo_envio',
        'total_final',
        'moneda',
        'nota_cliente',
        'nota_interna',
    ];

    protected $casts = [
        'total_productos' => 'decimal:2',
        'costo_envio'     => 'decimal:2',
        'total_final'     => 'decimal:2',
    ];

    /* ================== AUTORIZACIÓN ================== */

    /**
     * Busca un pedido y verifica que el request tenga autorización:
     * - Admin autenticado → acceso libre
     * - Cliente autenticado dueño del pedido → acceso
     * - Guest con access_token válido → acceso
     * - Cualquier otro caso → 403
     */
    public static function findAuthorizedOrFail(int $pedidoId, Request $request): object
    {
        $pedido = DB::table('pedidos')->where('id', $pedidoId)->first();

        if (!$pedido) {
            abort(404, 'Pedido no encontrado.');
        }

        $user = auth('sanctum')->user();

        if ($user && $user->rol === 'admin') {
            return $pedido;
        }

        if ($user && $user->cliente && (int) $pedido->cliente_id === (int) $user->cliente->id) {
            return $pedido;
        }

        $token = $request->input('access_token');
        if ($token && $pedido->access_token && hash_equals($pedido->access_token, $token)) {
            return $pedido;
        }

        abort(403, 'No autorizado para acceder a este pedido.');
    }

    /* ================== RELACIONES ================== */

    public function pagos()
    {
        return $this->hasMany(Pago::class);
    }

    public function reservasStock()
    {
        return $this->hasMany(ReservaStock::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function items()
    {
        return $this->hasMany(PedidoItem::class, 'pedido_id');
    }

    public function envio()
    {
        return $this->hasOne(Envio::class, 'pedido_id');
    }

    public function contenedorReservas(): HasMany
    {
        return $this->hasMany(ContenedorReserva::class, 'pedido_id');
    }
}
