<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class VenteController extends Controller
{
    public function index(Request $request)
    {
        $dateStr   = $request->get('date', Carbon::yesterday()->format('Y-m-d'));
        $date      = Carbon::parse($dateStr);
        $tableName = 'servmcljournal' . $date->format('Ymd');

        if (!Schema::hasTable($tableName)) {
            return response()->json([
                'success' => false,
                'message' => "Aucune donnée pour le {$date->format('d/m/Y')} (table {$tableName} introuvable).",
                'ventes'  => [],
                'stats'   => null,
                'date'    => $dateStr,
            ]);
        }

        $search = $request->get('search');

        $ventes = DB::table($tableName)
            ->when(
                $search,
                fn($q) =>
                $q->where('idcint', 'like', "%{$search}%")
                    ->orWhere('idlib', 'like', "%{$search}%")
            )
            ->where('idannul', 0)   // Exclure les ventes annulées
            ->orderBy('idquand', 'asc')
            ->paginate(50)
            ->withQueryString();

        $topProduit = DB::table($tableName)
            ->select('idcint', 'idlib', DB::raw('SUM(idqte) as total_vendu'))
            ->where('idannul', 0)
            ->groupBy('idcint', 'idlib')
            ->orderByDesc('total_vendu')
            ->first();

        $stats = [
            'total_lignes'  => DB::table($tableName)->where('idannul', 0)->count(),
            'total_montant' => DB::table($tableName)->where('idannul', 0)->sum('idmttnet') ?? 0,
            'top_produit'   => $topProduit?->idlib ?? '—',
            'date_affichee' => $date->format('d/m/Y'),
        ];

        return response()->json([
            'success'    => true,
            'ventes'     => $ventes,
            'stats'      => $stats,
            'topProduit' => $topProduit,
            'date'       => $dateStr,
        ]);
    }

    public function availableDates()
    {
        $tables = DB::select("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name LIKE 'servmcljournal%'
            ORDER BY table_name DESC
            LIMIT 90
        ");

        $dates = collect($tables)->map(function ($t) {
            $raw  = str_replace('servmcljournal', '', $t->table_name);
            $date = Carbon::createFromFormat('Ymd', $raw);
            return [
                'table' => $t->table_name,
                'date'  => $date->format('Y-m-d'),
                'label' => $date->format('d/m/Y'),
            ];
        });

        return response()->json(['dates' => $dates]);
    }
}
