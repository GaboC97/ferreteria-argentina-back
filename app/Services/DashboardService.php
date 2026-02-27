<?php

namespace App\Services;

use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Retorna todas las estadísticas del dashboard admin.
     */
    public function getStats(): array
    {
        $mesActual      = now()->startOfMonth();
        $mesAnterior    = now()->subMonth()->startOfMonth();
        $finMesAnterior = now()->subMonth()->endOfMonth();

        // 1. Ingresos totales del mes actual
        $totalRevenue = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->where('created_at', '>=', $mesActual)
            ->sum('total_final');

        $totalRevenueAnterior = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->sum('total_final');

        $revenueTrend = $this->calculateTrend($totalRevenue, $totalRevenueAnterior);

        // 2. Cantidad de pedidos del mes
        $ordersCount = DB::table('pedidos')
            ->where('created_at', '>=', $mesActual)
            ->count();

        $ordersCountAnterior = DB::table('pedidos')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->count();

        $ordersTrend = $this->calculateTrend($ordersCount, $ordersCountAnterior);

        // 3. Nuevos clientes del mes
        $newCustomers = DB::table('clientes')
            ->where('created_at', '>=', $mesActual)
            ->count();

        $newCustomersAnterior = DB::table('clientes')
            ->whereBetween('created_at', [$mesAnterior, $finMesAnterior])
            ->count();

        $customersTrend = $this->calculateTrend($newCustomers, $newCustomersAnterior);

        // 4. Pedidos pendientes de envío
        $pendingOrders = DB::table('pedidos')
            ->where('estado', 'pagado')
            ->whereIn('tipo_entrega', ['envio'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('envios')
                    ->whereColumn('envios.pedido_id', 'pedidos.id')
                    ->where('envios.estado', 'entregado');
            })
            ->count();

        // 5. Últimos pedidos
        $recentOrders = Pedido::with(['cliente', 'sucursal'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($pedido) {
                return [
                    'id'           => $pedido->id,
                    'customer'     => $pedido->nombre_contacto ?? $pedido->cliente?->nombre ?? 'Sin nombre',
                    'date'         => $pedido->created_at,
                    'status'       => $this->translateStatus($pedido->estado),
                    'total'        => (float) $pedido->total_final,
                    'tipo_entrega' => $pedido->tipo_entrega,
                    'sucursal'     => $pedido->sucursal?->nombre ?? 'N/A',
                ];
            });

        // 6. Productos más vendidos del mes
        $topProducts = DB::table('pedido_items')
            ->join('pedidos', 'pedido_items.pedido_id', '=', 'pedidos.id')
            ->join('productos', 'pedido_items.producto_id', '=', 'productos.id')
            ->select(
                'productos.id',
                'productos.nombre',
                DB::raw('SUM(pedido_items.cantidad) as total_sales'),
                DB::raw('COUNT(DISTINCT pedido_items.pedido_id) as orders_count')
            )
            ->where('pedidos.created_at', '>=', $mesActual)
            ->where('pedidos.estado', 'pagado')
            ->groupBy('productos.id', 'productos.nombre')
            ->orderBy('total_sales', 'desc')
            ->limit(5)
            ->get();

        $maxSales = $topProducts->max('total_sales') ?? 1;
        $topProductsFormatted = $topProducts->map(function ($product) use ($maxSales) {
            return [
                'name'        => $product->nombre,
                'sales'       => $product->total_sales,
                'ordersCount' => $product->orders_count,
                'percentage'  => ($product->total_sales / $maxSales) * 100,
            ];
        });

        return [
            'stats' => [
                'totalRevenue'  => (float) $totalRevenue,
                'revenueTrend'  => $revenueTrend,
                'ordersCount'   => $ordersCount,
                'ordersTrend'   => $ordersTrend,
                'newCustomers'  => $newCustomers,
                'customersTrend' => $customersTrend,
                'pendingOrders' => $pendingOrders,
            ],
            'recentOrders' => $recentOrders,
            'topProducts'  => $topProductsFormatted,
        ];
    }

    private function calculateTrend($current, $previous): float|int
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function translateStatus(string $status): string
    {
        $statuses = [
            'pendiente_pago' => 'Pendiente',
            'pagado'         => 'Completado',
            'enviado'        => 'Enviado',
            'entregado'      => 'Entregado',
            'cancelado'      => 'Cancelado',
            'fallido'        => 'Fallido',
        ];

        return $statuses[$status] ?? $status;
    }
}
