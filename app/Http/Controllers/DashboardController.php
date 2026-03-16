<?php

namespace App\Http\Controllers;

use App\Models\Stocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $hier      = Carbon::yesterday();
        $hierDate  = $hier->format('Y-m-d');
        $hierLabel = $hier->format('d/m/Y');

        // ── Ventes d'hier (table servmcljournal + date hier) ──────────────────
        $ventesHier = $this->getVentesHier($hier);

        // ── Achats d'hier ─────────────────────────────────────────────────────
        $achatsHier = DB::table('achats')
            ->whereDate('date', $hierDate)
            ->selectRaw('COUNT(*) as nb_lignes, ROUND(SUM(PrixU * QuantiteAchat), 2) as montant_total, SUM(QuantiteAchat) as qte_totale')
            ->first();

        $achatsHierDetail = DB::table('achats')
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

        // ── Stats globales ─────────────────────────────────────────────────────
        $stats = [
            'total_articles' => DB::table('articles')->count(),
            'valeur_stock'   => (float) Stocks::sum('PrixTotal'),
            'ruptures'       => Stocks::where('QuantiteStock', '<=', 0)->count(),
            'stock_faible'   => Stocks::where('QuantiteStock', '>', 0)
                ->where('QuantiteStock', '<=', 5)->count(),
        ];

        // ── Stocks en rupture (Qte = 0) ───────────────────────────────────────
        $ruptures = Stocks::where('QuantiteStock', '<=', 0)
            ->orderBy('Liblong')
            ->limit(10)
            ->get(['Code', 'Liblong', 'QuantiteStock', 'PrixU']);

        // ── Stocks faibles (0 < Qte <= 5) ────────────────────────────────────
        $stocksFaibles = Stocks::where('QuantiteStock', '>', 0)
            ->where('QuantiteStock', '<=', 5)
            ->orderBy('QuantiteStock')
            ->limit(10)
            ->get(['Code', 'Liblong', 'QuantiteStock', 'PrixU']);

        return Inertia::render('dashboard', [
            'hierLabel'        => $hierLabel,
            'stats'            => $stats,
            'ventesHier'       => $ventesHier,
            'achatsHier'       => [
                'nb_lignes'    => (int)   ($achatsHier->nb_lignes    ?? 0),
                'montant'      => (float) ($achatsHier->montant_total ?? 0),
                'qte_totale'   => (float) ($achatsHier->qte_totale   ?? 0),
                'detail'       => $achatsHierDetail,
            ],
            'ruptures'         => $ruptures,
            'stocksFaibles'    => $stocksFaibles,
        ]);
    }

    // ── Ventes d'hier depuis la table servmcljournal{Ymd} ─────────────────────
    private function getVentesHier(Carbon $hier): array
    {
        $tableName = 'servmcljournal' . $hier->format('Ymd');

        if (!Schema::hasTable($tableName)) {
            return [
                'disponible'    => false,
                'table'         => $tableName,
                'montant_net'   => 0,
                'nb_lignes'     => 0,
                'qte_totale'    => 0,
                'top_articles'  => [],
                'par_heure'     => [],
            ];
        }

        // Totaux
        $totaux = DB::table($tableName)
            ->where('idannul', 0)
            ->selectRaw('
                COUNT(*) as nb_lignes,
                COALESCE(SUM(CAST(idmttnet AS DECIMAL(15,2))), 0) as montant_net,
                COALESCE(SUM(CAST(E1 AS DECIMAL(10,3))), 0) as qte_totale
            ')
            ->first();

        // Détail ventes (groupé par article, trié par montant desc)
        $detail = DB::table($tableName)
            ->where('idannul', 0)
            ->selectRaw('
                idcint AS code,
                idlib  AS liblong,
                COALESCE(SUM(CAST(E1 AS DECIMAL(10,3))), 0)          AS qte,
                COALESCE(SUM(CAST(idmttnet AS DECIMAL(15,2))), 0)     AS montant
            ')
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
    }
}
