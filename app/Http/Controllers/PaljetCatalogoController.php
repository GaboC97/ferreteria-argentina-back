<?php

namespace App\Http\Controllers;

use App\Models\PaljetArticulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaljetCatalogoController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $onlyWeb = filter_var($request->query('only_web', '1'), FILTER_VALIDATE_BOOLEAN);

        // Lista de precios (PalJet) opcional
        $listaId = $request->query('lista_id');
        $listaId = is_null($listaId) ? null : (int) $listaId;

        // Stock: por depósito o total (opcionales)
        $depositoId = $request->query('deposito_id');
        $depositoId = is_null($depositoId) ? null : (int) $depositoId;

        $stockTotal = filter_var($request->query('stock_total', '0'), FILTER_VALIDATE_BOOLEAN);

        // Base query (siempre desde paljet_articulos)
        $query = PaljetArticulo::query();

        if ($onlyWeb) {
            $query->where('publica_web', true);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('descripcion', 'like', "%{$q}%")
                    ->orWhere('codigo', 'like', "%{$q}%")
                    ->orWhere('ean', 'like', "%{$q}%")
                    ->orWhere('desc_cliente', 'like', "%{$q}%")
                    ->orWhere('familia_path', 'like', "%{$q}%");
            });
        }

        /**
         * PRECIOS (lista)
         */
        if (!is_null($listaId)) {
            $query->leftJoin('paljet_precios as pp', function ($join) use ($listaId) {
                $join->on('pp.articulo_id', '=', 'paljet_articulos.paljet_id')
                    ->where('pp.lista_id', '=', $listaId);
            })
            ->addSelect('paljet_articulos.*')
            ->addSelect([
                'precio_sin_iva' => 'pp.pr_vta',
                'precio_con_iva' => 'pp.pr_final',
            ]);
        } else {
            // Si no hay join, igual aseguramos que seleccione columnas del modelo
            $query->addSelect('paljet_articulos.*');
        }

        /**
         * STOCK (por depósito o total)
         *
         * - Si viene deposito_id -> devuelve stock de ese depósito.
         * - Si NO viene deposito_id y viene stock_total=true -> suma stock de todos los depósitos.
         * - Si no viene nada -> no agrega stock.
         */
        if (!is_null($depositoId)) {
            $query->leftJoin('paljet_stock as ps', function ($join) use ($depositoId) {
                $join->on('ps.articulo_id', '=', 'paljet_articulos.paljet_id')
                    ->where('ps.deposito_id', '=', $depositoId);
            })
            ->addSelect([
                'stock_existencia' => 'ps.existencia',
                'stock_disponible' => 'ps.disponible',
            ]);
        } elseif ($stockTotal) {
            $query->leftJoin('paljet_stock as pst', function ($join) {
                $join->on('pst.articulo_id', '=', 'paljet_articulos.paljet_id');
            })
            ->addSelect([
                DB::raw('COALESCE(SUM(pst.existencia),0) as stock_existencia'),
                DB::raw('COALESCE(SUM(pst.disponible),0) as stock_disponible'),
            ])
            // agrupamos por la PK real de paljet_articulos, para que SUM funcione
            ->groupBy('paljet_articulos.id');
        }

        $query->orderBy('descripcion');

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function show(int $paljetId)
    {
        $item = PaljetArticulo::where('paljet_id', $paljetId)->firstOrFail();
        return response()->json($item);
    }
}
