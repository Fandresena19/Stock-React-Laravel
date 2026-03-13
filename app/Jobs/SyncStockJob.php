<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SyncStockJob
 * ─────────────────────────────────────────────────────────────────────────────
 * Ce job est dispatché quand l'utilisateur visite /stocks.
 * Il tourne dans un worker séparé (php artisan queue:work).
 * L'utilisateur voit sa page immédiatement — le calcul se fait en parallèle.
 *
 * QUEUE : database (zéro dépendance, fonctionne partout)
 *
 * DÉMARRER LE WORKER (une seule fois, dans un terminal séparé) :
 *   php artisan queue:work --queue=stock --sleep=3 --tries=3
 *
 * CE QUE LE JOB FAIT :
 *   1. syncArticlesManquants()  → INSERT articles absents de stocks
 *   2. syncVentesStock()        → recalcule achats - ventes (tables modifiées seulement)
 *   3. syncPrixU()              → met à jour PrixU depuis le dernier achat
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // 5 minutes max pour 200+ tables

    public function __construct(
        public readonly bool $syncArticles = true,
        public readonly bool $syncVentes   = true,
        public readonly bool $syncPrixU    = true,
    ) {}

    public function handle(): void
    {
        Log::info('SyncStockJob: démarrage', [
            'articles' => $this->syncArticles,
            'ventes'   => $this->syncVentes,
            'prixU'    => $this->syncPrixU,
        ]);

        if ($this->syncArticles) $this->syncArticlesManquants();
        if ($this->syncVentes)   $this->syncVentesStock();
        if ($this->syncPrixU)    $this->syncPrixUDepuisAchats();

        Log::info('SyncStockJob: terminé.');
    }

    // =========================================================================
    // 1. Articles présents dans articles mais absents de stocks
    //    + sync Liblong/fournisseur
    // =========================================================================

    private function syncArticlesManquants(): void
    {
        try {
            $missing = DB::table('articles')
                ->leftJoin('stocks', 'articles.Code', '=', 'stocks.Code')
                ->whereNull('stocks.Code')
                ->select('articles.Code', 'articles.Liblong', 'articles.fournisseur')
                ->get();

            if ($missing->isNotEmpty()) {
                foreach ($missing->chunk(500) as $chunk) {
                    DB::table('stocks')->insertOrIgnore(
                        $chunk->map(fn($a) => [
                            'Code'          => $a->Code,
                            'Liblong'       => $a->Liblong,
                            'fournisseur'   => $a->fournisseur,
                            'QuantiteStock' => 0,
                            'PrixU'         => 0,
                            'PrixTotal'     => 0,
                        ])->toArray()
                    );
                }
                Log::info('SyncStockJob: ' . $missing->count() . ' article(s) insérés dans stocks.');
            }

            // Sync Liblong + fournisseur en 1 requête bulk
            DB::statement('
                UPDATE stocks s
                INNER JOIN articles a ON s.Code = a.Code
                SET s.Liblong     = a.Liblong,
                    s.fournisseur = a.fournisseur
            ');

            cache()->put('sync_articles_ok', true, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncArticles: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 2. Ventes depuis les 200+ tables servmcljournal*
    //    Traite UNIQUEMENT les tables dont UPDATE_TIME a changé → performant
    // =========================================================================

    private function syncVentesStock(): void
    {
        try {
            $database = DB::getDatabaseName();

            $venteTables = DB::select('
                SELECT t.TABLE_NAME, t.UPDATE_TIME
                FROM INFORMATION_SCHEMA.TABLES t
                WHERE t.TABLE_SCHEMA = ?
                  AND t.TABLE_NAME LIKE \'servmcljournal%\'
                  AND (
                      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
                      WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
                        AND c.TABLE_NAME   = t.TABLE_NAME
                        AND c.COLUMN_NAME IN (\'idcint\', \'E1\')
                  ) = 2
                ORDER BY t.TABLE_NAME DESC
            ', [$database]);

            if (empty($venteTables)) {
                cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
                return;
            }

            // Seulement les tables dont le contenu a changé
            $tablesModifiees = array_filter(
                $venteTables,
                fn($row) => cache()->get('vente_chk_' . $row->TABLE_NAME) !== (string) $row->UPDATE_TIME
            );

            if (empty($tablesModifiees)) {
                Log::info('SyncStockJob::syncVentes: aucune table modifiée.');
                cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
                return;
            }

            Log::info('SyncStockJob::syncVentes: ' . count($tablesModifiees) . ' table(s) modifiée(s) sur ' . count($venteTables));

            // Agréger toutes les ventes (toutes les tables pour total cohérent)
            $ventesTotaux = [];
            foreach ($venteTables as $row) {
                try {
                    $rows = DB::select("
                        SELECT idcint AS Code, COALESCE(SUM(E1), 0) AS total_vente
                        FROM `{$row->TABLE_NAME}` GROUP BY idcint
                    ");
                    foreach ($rows as $r) {
                        $ventesTotaux[$r->Code] = ($ventesTotaux[$r->Code] ?? 0.0) + (float) $r->total_vente;
                    }
                } catch (\Throwable $e) {
                    Log::warning('SyncStockJob: table ' . $row->TABLE_NAME . ' ignorée — ' . $e->getMessage());
                }
            }

            // Totaux achats
            $achats = DB::table('achats')
                ->selectRaw('Code, COALESCE(SUM(QuantiteAchat), 0) AS total_achat')
                ->groupBy('Code')
                ->get()
                ->keyBy('Code');

            // Bulk UPDATE par chunks de 500
            $stocks = DB::table('stocks')->select('Code', 'PrixU')->get();

            foreach ($stocks->chunk(500) as $chunk) {
                $qteCase   = 'CASE `Code`';
                $totalCase = 'CASE `Code`';
                $codes     = [];

                foreach ($chunk as $stock) {
                    $code   = $stock->Code;
                    $prixU  = (float) ($stock->PrixU ?? 0);
                    $newQte = (isset($achats[$code]) ? (float) $achats[$code]->total_achat : 0.0)
                        - ($ventesTotaux[$code] ?? 0.0);
                    $q      = DB::getPdo()->quote($code);

                    $qteCase   .= " WHEN {$q} THEN {$newQte}";
                    $totalCase .= ' WHEN ' . $q . ' THEN ' . ($newQte * $prixU);
                    $codes[]    = $q;
                }

                if (empty($codes)) continue;

                DB::statement('
                    UPDATE stocks
                    SET QuantiteStock = ' . $qteCase . ' END,
                        PrixTotal     = ' . $totalCase . ' END
                    WHERE `Code` IN (' . implode(',', $codes) . ')
                ');
            }

            // Marquer les tables modifiées comme traitées
            foreach ($tablesModifiees as $row) {
                cache()->put('vente_chk_' . $row->TABLE_NAME, (string) $row->UPDATE_TIME, now()->addDays(7));
            }

            cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncVentes: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. PrixU depuis le dernier achat par article
    // =========================================================================

    private function syncPrixUDepuisAchats(): void
    {
        try {
            $derniersPrix = DB::table('achats as a')
                ->select('a.Code', 'a.PrixU')
                ->whereRaw('a.date = (SELECT MAX(date) FROM achats WHERE Code = a.Code)')
                ->get()
                ->keyBy('Code');

            if ($derniersPrix->isEmpty()) {
                cache()->put('sync_prixu_ok', true, now()->addMinutes(5));
                return;
            }

            $quantites = DB::table('stocks')
                ->whereIn('Code', $derniersPrix->keys()->toArray())
                ->select('Code', 'QuantiteStock')
                ->get()
                ->keyBy('Code');

            foreach ($derniersPrix->chunk(500) as $chunk) {
                $prixCase  = 'CASE `Code`';
                $totalCase = 'CASE `Code`';
                $codes     = [];

                foreach ($chunk as $code => $row) {
                    if (!isset($quantites[$code])) continue;
                    $prixU    = (float) $row->PrixU;
                    $newTotal = (float) $quantites[$code]->QuantiteStock * $prixU;
                    $q        = DB::getPdo()->quote($code);

                    $prixCase  .= " WHEN {$q} THEN {$prixU}";
                    $totalCase .= " WHEN {$q} THEN {$newTotal}";
                    $codes[]    = $q;
                }

                if (empty($codes)) continue;

                DB::statement('
                    UPDATE stocks
                    SET PrixU     = ' . $prixCase . ' END,
                        PrixTotal = ' . $totalCase . ' END
                    WHERE `Code` IN (' . implode(',', $codes) . ')
                ');
            }

            cache()->put('sync_prixu_ok', true, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncPrixU: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncStockJob: échec définitif', [
            'message' => $exception->getMessage(),
        ]);
    }
}
