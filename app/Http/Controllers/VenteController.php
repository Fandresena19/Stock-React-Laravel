<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class VenteController extends Controller
{
    private const DB_VENTES = 'mysql_ventes';

    // Formule de conversion E1 (char avec virgule) → DECIMAL
    private const E1_DECIMAL = "CAST(REPLACE(E1, ',', '.') AS DECIMAL(10,3))";

    public function index(Request $request)
    {
        $dateStr   = $request->get('date', Carbon::yesterday()->format('Y-m-d'));
        $date      = Carbon::parse($dateStr);
        $tableName = 'servmcljournal' . $date->format('Ymd');
        $db        = DB::connection(self::DB_VENTES);

        if (!Schema::connection(self::DB_VENTES)->hasTable($tableName)) {
            return response()->json([
                'success' => false,
                'message' => "Aucune donnée pour le {$date->format('d/m/Y')} (table {$tableName} introuvable).",
                'ventes'  => [],
                'stats'   => null,
                'date'    => $dateStr,
            ]);
        }

        $search = $request->get('search');
        $e1     = self::E1_DECIMAL;

        // E1 converti en DECIMAL et retourné comme idqte
        // → le frontend affiche v.idqte directement
        $ventes = $db->table($tableName)
            ->selectRaw("idquand, idcint, idlib, {$e1} AS idqte, idmttnet")
            ->when(
                $search,
                fn($q) =>
                $q->where('idcint', 'like', "%{$search}%")
                    ->orWhere('idlib',  'like', "%{$search}%")
            )
            ->orderBy('idquand', 'asc')
            ->paginate(50)
            ->withQueryString();

        // Top produit par quantité E1
        $topProduit = $db->table($tableName)
            ->selectRaw("idcint, idlib, SUM({$e1}) AS total_vendu")
            ->groupBy('idcint', 'idlib')
            ->orderByDesc('total_vendu')
            ->first();

        $stats = [
            'total_lignes'  => $db->table($tableName)->count(),
            'total_montant' => (float) ($db->table($tableName)->sum('idmttnet') ?? 0),
            'total_qte'     => (float) ($db->table($tableName)->selectRaw("SUM({$e1}) as t")->value('t') ?? 0),
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
        $db       = DB::connection(self::DB_VENTES);
        $database = $db->getDatabaseName();

        $tables = $db->select("
            SELECT TABLE_NAME AS table_name
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME LIKE 'servmcljournal%'
            ORDER BY TABLE_NAME DESC
            LIMIT 90
        ", [$database]);

        $dates = collect($tables)->map(function ($t) {
            $raw = str_replace('servmcljournal', '', $t->table_name);
            try {
                $date = Carbon::createFromFormat('Ymd', $raw);
                return [
                    'table' => $t->table_name,
                    'date'  => $date->format('Y-m-d'),
                    'label' => $date->format('d/m/Y'),
                ];
            } catch (\Throwable) {
                return null;
            }
        })->filter()->values();

        return response()->json(['dates' => $dates]);
    }
}
