<?php

namespace App\Http\Controllers;

use App\Services\VenteStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class VenteController extends Controller
{
    public function __construct(private VenteStockService $stockService) {}

    // =========================================================================
    // INDEX — lecture des ventes du jour demandé (inchangé)
    // =========================================================================

    public function index(Request $request)
    {
        $dateStr   = $request->get('date', Carbon::yesterday()->format('Y-m-d'));
        $date      = Carbon::parse($dateStr);
        $tableName = 'servmcljournal' . $date->format('Ymd');

        if (!Schema::hasTable($tableName)) {
            return response()->json([
                'success'    => false,
                'message'    => "Aucune donnée pour le {$date->format('d/m/Y')} (table {$tableName} introuvable).",
                'ventes'     => [],
                'stats'      => null,
                'topProduit' => null,
                'date'       => $dateStr,
            ]);
        }

        $search = $request->get('search');

        $ventes = DB::table($tableName)
            ->when($search, function ($query) use ($search) {
                $query->where('idcint', 'like', "%{$search}%")
                    ->orWhere('idlib',  'like', "%{$search}%");
            })
            ->orderBy('idquand', 'asc')
            ->paginate(50)
            ->withQueryString();

        $topProduit = DB::table($tableName)
            ->select('idcint', 'idlib', DB::raw('SUM(E1) as total_vendu'))
            ->groupBy('idcint', 'idlib')
            ->orderByDesc('total_vendu')
            ->first();

        $stats = [
            'total_lignes'  => DB::table($tableName)->count(),
            'total_montant' => DB::table($tableName)->sum('idmttnet') ?? 0,
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

    // =========================================================================
    // STORE — enregistrer une vente ET mettre à jour le stock
    // =========================================================================

    /**
     * Enregistre une ou plusieurs lignes de vente dans la table dynamique du jour
     * et met à jour le stock de façon incrémentale via VenteStockService.
     *
     * Payload attendu :
     *   {
     *     "date":   "2025-03-10",          // optionnel, défaut = aujourd'hui
     *     "ventes": [
     *       { "idcint": "ART001", "idlib": "Article 1", "E1": 3, "idmttnet": 150.00 },
     *       ...
     *     ]
     *   }
     */
    public function store(Request $request)
    {
        $request->validate([
            'ventes'             => 'required|array|min:1',
            'ventes.*.idcint'    => 'required|string',
            'ventes.*.E1'        => 'required|numeric',
            'ventes.*.idmttnet'  => 'nullable|numeric',
            'date'               => 'nullable|date',
        ]);

        $date      = Carbon::parse($request->get('date', now()))->format('Y-m-d');
        $tableName = 'servmcljournal' . Carbon::parse($date)->format('Ymd');

        // Créer la table du jour si elle n'existe pas
        if (!Schema::hasTable($tableName)) {
            DB::statement("
                CREATE TABLE `{$tableName}` (
                    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    idquand   DATETIME,
                    idcint    VARCHAR(50),
                    idlib     VARCHAR(255),
                    E1        DECIMAL(15,4) DEFAULT 0,
                    idmttnet  DECIMAL(15,4) DEFAULT 0
                )
            ");
        }

        $rows = collect($request->ventes)->map(fn($v) => [
            'idquand'  => now(),
            'idcint'   => $v['idcint'],
            'idlib'    => $v['idlib']     ?? '',
            'E1'       => $v['E1'],
            'idmttnet' => $v['idmttnet'] ?? 0,
        ])->toArray();

        DB::table($tableName)->insert($rows);

        // ── Mise à jour du stock (incrémentale, via service) ─────────────────
        $ventesData = collect($request->ventes)->map(fn($v) => [
            'code'     => $v['idcint'],
            'quantite' => (float) $v['E1'],
        ])->toArray();

        $this->stockService->diminuerStockBulk($ventesData);

        return response()->json([
            'success' => true,
            'message' => count($rows) . ' ligne(s) de vente enregistrée(s).',
        ], 201);
    }

    // =========================================================================
    // AVAILABLE DATES — inchangé
    // =========================================================================

    public function availableDates()
    {
        $tables = DB::select("
            SELECT table_name
            FROM information_schema.tables
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
