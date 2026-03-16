<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricasController extends Controller
{
    // Estados que cuentan como ingreso en tabla `pedidos`
    private const ESTADOS_INGRESO = ['pagado', 'entregado'];

    // Estado que cuenta como ingreso en tabla `pedidos_historicos` (wc-completed → completado)
    private const ESTADO_HISTORICO_INGRESO = 'completado';

    /**
     * Suma de ingresos combinando pedidos actuales + históricos de WooCommerce para un año.
     */
    private function ingresosTotalesPorAno(int $year): float
    {
        $actual = (float) DB::table('pedidos')
            ->whereIn('estado', self::ESTADOS_INGRESO)
            ->whereYear('created_at', $year)
            ->sum('total_final');

        $historico = (float) DB::table('pedidos_historicos')
            ->where('estado', self::ESTADO_HISTORICO_INGRESO)
            ->whereYear('fecha', $year)
            ->sum('total');

        return $actual + $historico;
    }

    /**
     * Retorna un mapa [mes => total] combinando pedidos actuales + históricos.
     * $year: año a consultar.
     */
    private function ingresosMensualesMapa(int $year): array
    {
        $actual = DB::table('pedidos')
            ->whereIn('estado', self::ESTADOS_INGRESO)
            ->whereYear('created_at', $year)
            ->selectRaw('MONTH(created_at) as mes, SUM(total_final) as total')
            ->groupBy('mes')
            ->pluck('total', 'mes');

        $historico = DB::table('pedidos_historicos')
            ->where('estado', self::ESTADO_HISTORICO_INGRESO)
            ->whereYear('fecha', $year)
            ->selectRaw('MONTH(fecha) as mes, SUM(total) as total')
            ->groupBy('mes')
            ->pluck('total', 'mes');

        $mapa = [];
        for ($m = 1; $m <= 12; $m++) {
            $mapa[$m] = (float) ($actual[$m] ?? 0) + (float) ($historico[$m] ?? 0);
        }

        return $mapa;
    }

    /**
     * GET /admin/metricas/kpis?year=2025
     */
    public function kpis(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $ingresos = $this->ingresosTotalesPorAno($year);

        $totalPedidosActuales = DB::table('pedidos')
            ->whereYear('created_at', $year)
            ->count();

        $totalPedidosHistoricos = DB::table('pedidos_historicos')
            ->whereYear('fecha', $year)
            ->count();

        $totalPedidos = $totalPedidosActuales + $totalPedidosHistoricos;

        $clientesNuevos = DB::table('users')
            ->where('rol', 'cliente')
            ->whereYear('created_at', $year)
            ->count();

        $ticketPromedio = $totalPedidos > 0 ? $ingresos / $totalPedidos : 0;

        return response()->json([
            'total_ingresos'  => round($ingresos, 2),
            'total_pedidos'   => $totalPedidos,
            'ticket_promedio' => round($ticketPromedio, 2),
            'clientes_nuevos' => $clientesNuevos,
        ]);
    }

    /**
     * GET /admin/metricas/ingresos-mensuales?year=2025
     */
    public function ingresosMensuales(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $mapa = $this->ingresosMensualesMapa($year);

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[] = round($mapa[$m], 2);
        }

        return response()->json($result);
    }

    /**
     * GET /admin/metricas/pedidos-por-estado?year=2025
     * Combina estados de pedidos actuales + históricos.
     */
    public function pedidosPorEstado(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $actuales = DB::table('pedidos')
            ->whereYear('created_at', $year)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        $historicos = DB::table('pedidos_historicos')
            ->whereYear('fecha', $year)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        // Merge: sumar si el estado ya existe, agregar si es nuevo
        foreach ($historicos as $estado => $count) {
            $actuales[$estado] = ($actuales[$estado] ?? 0) + $count;
        }

        return response()->json($actuales);
    }

    /**
     * GET /admin/metricas/comparativa-anual
     * Ingresos mensuales de los últimos 3 años (pedidos actuales + históricos).
     */
    public function comparativaAnual()
    {
        $currentYear = (int) now()->year;
        $years = [$currentYear - 2, $currentYear - 1, $currentYear];

        $data = [];
        foreach ($years as $year) {
            $mapa = $this->ingresosMensualesMapa($year);
            $data[(string) $year] = array_values(array_map(fn($v) => round($v, 2), $mapa));
        }

        return response()->json($data);
    }
}
