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
 * Lancé en arrière-plan via :
 *   php artisan queue:work --queue=stock --sleep=3 --tries=3
 *
 * Déclenché automatiquement à chaque visite de /stocks (si cache expiré).
 *
 * Ce que le job fait :
 *   1. syncArticlesManquants() — INSERT articles absents de stocks
 *   2. syncVentesStock()       — QuantiteStock = SUM(achats) - SUM(ventes)
 *   3. syncPrixU()             — PrixU = dernier prix d'achat par article
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 3600;

    public function handle(): void
    {
        Log::info('SyncStockJob: démarrage');

        $this->syncArticlesManquants();
        $this->syncVentesStock();
        $this->syncPrixU();

        // Valide pendant 6h — évite de relancer le job à chaque visite
        cache()->put('sync_done', true, now()->addHours(6));
        cache()->forget('sync_job_dispatched');

        Log::info('SyncStockJob: terminé.');
    }

    // =========================================================================
    // 1. Insérer les articles manquants dans stocks
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
                Log::info('SyncStockJob: ' . $missing->count() . ' article(s) insérés.');
            }

            // Sync Liblong + fournisseur depuis articles
            DB::statement('
                UPDATE stocks s
                INNER JOIN articles a ON s.Code COLLATE utf8mb3_unicode_ci = a.Code COLLATE utf8mb3_unicode_ci
                SET s.Liblong     = a.Liblong,
                    s.fournisseur = a.fournisseur
            ');
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncArticles: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 2. QuantiteStock = SUM(achats) - SUM(ventes)
    //    Utilise COUNT(*) par table comme signature de changement
    //    (UPDATE_TIME = NULL sous WAMP/Windows — non fiable)
    // =========================================================================

    private function syncVentesStock(): void
    {
        try {
            $database = DB::getDatabaseName();

            // Lister toutes les tables servmcljournal* avec colonnes idcint + E1
            $venteTables = DB::select("
                SELECT t.TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES t
                WHERE t.TABLE_SCHEMA = ?
                  AND t.TABLE_NAME LIKE 'servmcljournal%'
                  AND (
                      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
                      WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
                        AND c.TABLE_NAME   = t.TABLE_NAME
                        AND c.COLUMN_NAME IN ('idcint', 'E1')
                  ) = 2
                ORDER BY t.TABLE_NAME DESC
            ", [$database]);

            if (empty($venteTables)) {
                Log::info('SyncStockJob::syncVentes: aucune table servmcljournal trouvée.');
                return;
            }

            // Détecter les tables modifiées via COUNT(*) — fiable sous WAMP
            $tablesModifiees = [];
            foreach ($venteTables as $row) {
                try {
                    $count    = DB::table($row->TABLE_NAME)->count();
                    $cacheKey = 'vente_count_' . $row->TABLE_NAME;
                    $cached   = cache()->get($cacheKey);

                    if ($cached === null || (int) $cached !== $count) {
                        $tablesModifiees[] = ['name' => $row->TABLE_NAME, 'count' => $count];
                    }
                } catch (\Throwable) {
                    // Table inaccessible → ignorée
                }
            }

            if (empty($tablesModifiees)) {
                Log::info('SyncStockJob::syncVentes: aucune table modifiée.');
                return;
            }

            Log::info('SyncStockJob::syncVentes: ' . count($tablesModifiees) . '/' . count($venteTables) . ' table(s) modifiée(s).');

            // Agréger toutes les ventes (toutes les tables pour total cohérent)
            $ventesTotaux = [];
            foreach ($venteTables as $row) {
                try {
                    $rows = DB::select("
                        SELECT idcint AS Code, COALESCE(SUM(E1), 0) AS total_vente
                        FROM `{$row->TABLE_NAME}`
                        GROUP BY idcint
                    ");
                    foreach ($rows as $r) {
                        $ventesTotaux[$r->Code] = ($ventesTotaux[$r->Code] ?? 0.0) + (float) $r->total_vente;
                    }
                } catch (\Throwable $e) {
                    Log::warning('SyncStockJob: table ' . $row->TABLE_NAME . ' ignorée — ' . $e->getMessage());
                }
            }

            // Totaux achats par Code
            $achats = DB::table('achats')
                ->selectRaw('Code, COALESCE(SUM(QuantiteAchat), 0) AS total_achat')
                ->groupBy('Code')
                ->get()
                ->keyBy('Code');

            // Bulk UPDATE par chunks de 500 — CASE WHEN pour éviter N requêtes
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

            // Mettre à jour le cache COUNT par table
            foreach ($tablesModifiees as $table) {
                cache()->put('vente_count_' . $table['name'], $table['count'], now()->addDays(30));
            }

            Log::info('SyncStockJob::syncVentes: stocks mis à jour.');
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncVentes: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. PrixU = prix du dernier achat par article
    //
    // IMPORTANT : PrixTotal calculé en SQL pur (QuantiteStock * nouveauPrixU)
    // pour éviter le doublon avec syncVentesStock() qui a déjà mis à jour
    // QuantiteStock et PrixTotal. On relit QuantiteStock depuis la base.
    // =========================================================================

    private function syncPrixU(): void
    {
        try {
            $derniersPrix = DB::table('achats as a')
                ->select('a.Code', 'a.PrixU')
                ->whereRaw('a.date = (SELECT MAX(date) FROM achats WHERE Code = a.Code)')
                ->get()
                ->keyBy('Code');

            if ($derniersPrix->isEmpty()) return;

            foreach ($derniersPrix->chunk(500) as $chunk) {
                $prixCase = 'CASE `Code`';
                $codes    = [];

                foreach ($chunk as $code => $row) {
                    $prixU     = (float) $row->PrixU;
                    $q         = DB::getPdo()->quote($code);
                    $prixCase .= " WHEN {$q} THEN {$prixU}";
                    $codes[]   = $q;
                }

                if (empty($codes)) continue;

                // PrixTotal = QuantiteStock réelle en base * nouveauPrixU → 1 seul calcul
                DB::statement('
                    UPDATE stocks
                    SET PrixU     = ' . $prixCase . ' END,
                        PrixTotal = QuantiteStock * (' . $prixCase . ' END)
                    WHERE `Code` IN (' . implode(',', $codes) . ')
                ');
            }

            Log::info('SyncStockJob::syncPrixU: terminé.');
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncPrixU: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        cache()->forget('sync_job_dispatched');
        cache()->forget('sync_done');
        Log::error('SyncStockJob échoué: ' . $exception->getMessage());
    }
}
