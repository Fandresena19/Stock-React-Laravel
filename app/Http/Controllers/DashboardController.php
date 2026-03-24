<?php

namespace App\Http\Controllers;

use App\Models\Stocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    private const DB_VENTES = 'mysql_ventes';

    private const E1_DECIMAL = "CAST(REPLACE(E1, ',', '.') AS DECIMAL(10,3))";

    public function index()
    {
        $hier      = Carbon::yesterday();
        $hierDate  = $hier->format('Y-m-d');
        $hierLabel = $hier->format('d/m/Y');

        $stats = [
            'total_articles'  => DB::table('articles')->count(),
            'valeur_stock'    => (float) Stocks::sum('PrixTotal'),
            'ruptures'        => Stocks::where('QuantiteStock', '<=', 0)->count(),
            'stock_faible'    => Stocks::where('QuantiteStock', '>', 0)
                ->where('QuantiteStock', '<=', 5)->count(),
        ];

        $ruptures = Stocks::where('QuantiteStock', '<=', 0)
            ->orderBy('Liblong')->limit(10)
            ->get(['Code', 'Liblong', 'QuantiteStock', 'PrixU']);

        $stocksFaibles = Stocks::where('QuantiteStock', '>', 0)
            ->where('QuantiteStock', '<=', 5)
            ->orderBy('QuantiteStock')->limit(10)
            ->get(['Code', 'Liblong', 'QuantiteStock', 'PrixU']);

        return Inertia::render('dashboard', [
            'hierLabel'     => $hierLabel,
            'stats'         => $stats,
            'ventesHier'    => $this->getVentesHier($hier),
            'achatsHier'    => $this->getAchatsHier($hierDate),
            'ruptures'      => $ruptures,
            'stocksFaibles' => $stocksFaibles,
        ]);
    }

    // =========================================================================
    // Achats d'hier — base principale (mysql)
    // =========================================================================

    private function getAchatsHier(string $hierDate): array
    {
        try {
            $totaux = DB::table('achats')
                ->whereDate('date', $hierDate)
                ->selectRaw('COUNT(*) AS nb_lignes, ROUND(SUM(PrixU * QuantiteAchat), 2) AS montant_total, SUM(QuantiteAchat) AS qte_totale')
                ->first();

            $detail = DB::table('achats')
                ->whereDate('date', $hierDate)
                ->select('Code', 'Liblong', 'PrixU', 'QuantiteAchat')
                ->orderBy('Liblong')
                ->get()
                ->map(fn($a) => [
                    'Code'          => $a->Code,
                    'Liblong'       => $a->Liblong,
                    'PrixU'         => (float) $a->PrixU,
                    'QuantiteAchat' => (float) $a->QuantiteAchat,
                    'montant'       => round((float) $a->PrixU * (float) $a->QuantiteAchat, 2),
                ]);

            return [
                'nb_lignes'  => (int)   ($totaux->nb_lignes    ?? 0),
                'montant'    => (float) ($totaux->montant_total ?? 0),
                'qte_totale' => (float) ($totaux->qte_totale   ?? 0),
                'detail'     => $detail,
            ];
        } catch (\Throwable $e) {
            return ['nb_lignes' => 0, 'montant' => 0, 'qte_totale' => 0, 'detail' => []];
        }
    }

    // =========================================================================
    // Ventes d'hier — base ventes (mysql_ventes)
    // =========================================================================

    private function getVentesHier(Carbon $hier): array
    {
        $tableName = 'servmcljournal' . $hier->format('Ymd');
        $e1        = self::E1_DECIMAL;

        try {
            if (!Schema::connection(self::DB_VENTES)->hasTable($tableName)) {
                return [
                    'disponible'  => false,
                    'table'       => $tableName,
                    'montant_net' => 0,
                    'nb_lignes'   => 0,
                    'qte_totale'  => 0,
                    'detail'      => [],
                ];
            }

            $db = DB::connection(self::DB_VENTES);

            $totaux = $db->table($tableName)
                ->selectRaw("
                    COUNT(*)                                          AS nb_lignes,
                    COALESCE(SUM(CAST(idmttnet AS DECIMAL(15,2))), 0) AS montant_net,
                    COALESCE(SUM({$e1}), 0)                           AS qte_totale
                ")
                ->first();

            $detail = $db->table($tableName)
                ->selectRaw("
                    idcint                                            AS code,
                    idlib                                             AS liblong,
                    COALESCE(SUM({$e1}), 0)                           AS qte,
                    COALESCE(SUM(CAST(idmttnet AS DECIMAL(15,2))), 0) AS montant
                ")
                ->groupBy('idcint', 'idlib')
                ->orderByDesc('montant')
                ->get()
                ->map(fn($r) => [
                    'code'    => $r->code,
                    'liblong' => $r->liblong,
                    'qte'     => (float) $r->qte,
                    'montant' => (float) $r->montant,
                ]);

            return [
                'disponible'  => true,
                'table'       => $tableName,
                'montant_net' => (float) ($totaux->montant_net ?? 0),
                'nb_lignes'   => (int)   ($totaux->nb_lignes   ?? 0),
                'qte_totale'  => (float) ($totaux->qte_totale  ?? 0),
                'detail'      => $detail,
            ];
        } catch (\Throwable $e) {
            return [
                'disponible'  => false,
                'table'       => $tableName,
                'montant_net' => 0,
                'nb_lignes'   => 0,
                'qte_totale'  => 0,
                'detail'      => [],
            ];
        }
    }
}
