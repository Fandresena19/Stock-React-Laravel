<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SyncStockJob
 * ─────────────────────────────────────────────────────────────────────────────
 * Synchronise le stock avec les mouvements d'hier uniquement.
 *
 * E1 est char(30) avec virgule française ("0,174") →
 *   converti via CAST(REPLACE(E1, ',', '.') AS DECIMAL(10,3))
 *
 * CONNEXIONS :
 *   mysql        → stocks, achats, articles
 *   mysql_ventes → servmcljournal* (tables ventes)
 * ─────────────────────────────────────────────────────────────────────────────
 */
class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    private const DB_VENTES = 'mysql_ventes';
    private const E1_DECIMAL = "CAST(REPLACE(E1, ',', '.') AS DECIMAL(10,3))";

    public function handle(): void
    {
        $hier = Carbon::yesterday();
        Log::info('SyncStockJob: démarrage pour ' . $hier->format('d/m/Y'));

        $this->syncArticlesManquants();
        $this->syncMouvementsHier($hier);
        $this->syncPrixU($hier);

        cache()->put('sync_done', true, now()->addHours(6));
        cache()->forget('sync_job_dispatched');

        Log::info('SyncStockJob: terminé.');
    }

    // =========================================================================
    // 1. Articles manquants dans stocks
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

            DB::statement("
                UPDATE stocks s
                INNER JOIN articles a
                    ON s.Code COLLATE utf8mb3_unicode_ci = a.Code COLLATE utf8mb3_unicode_ci
                SET s.Liblong     = a.Liblong,
                    s.fournisseur = a.fournisseur
            ");
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncArticles: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 2. Ajustement incrémental d'hier
    //    QuantiteStock += achat_hier - vente_hier (E1 converti)
    // =========================================================================

    private function syncMouvementsHier(Carbon $hier): void
    {
        try {
            $hierDate  = $hier->format('Y-m-d');
            $tableName = 'servmcljournal' . $hier->format('Ymd');
            $e1        = self::E1_DECIMAL;

            // Achats d'hier
            $achatsHier = DB::table('achats')
                ->whereDate('date', $hierDate)
                ->selectRaw('Code, COALESCE(SUM(QuantiteAchat), 0) AS total_achat')
                ->groupBy('Code')
                ->get()
                ->keyBy('Code');

            Log::info('SyncStockJob: ' . $achatsHier->count() . ' article(s) achetés hier.');

            // Ventes d'hier — E1 converti en DECIMAL
            $ventesHier = collect();
            $dbVentes   = DB::connection(self::DB_VENTES);

            if (Schema::connection(self::DB_VENTES)->hasTable($tableName)) {
                $rows = $dbVentes->select("
                    SELECT idcint AS Code,
                           COALESCE(SUM({$e1}), 0) AS total_vente
                    FROM `{$tableName}`
                    GROUP BY idcint
                ");
                $ventesHier = collect($rows)->keyBy('Code');
                Log::info('SyncStockJob: ' . $ventesHier->count() . ' article(s) vendus hier (' . $tableName . ').');
            } else {
                Log::info('SyncStockJob: table ' . $tableName . ' absente — ventes = 0.');
            }

            if ($achatsHier->isEmpty() && $ventesHier->isEmpty()) {
                Log::info('SyncStockJob: aucun mouvement hier.');
                return;
            }

            // Tous les codes concernés hier
            $codes = $achatsHier->keys()
                ->merge($ventesHier->keys())
                ->unique()
                ->values();

            // Bulk UPDATE par chunks de 500
            foreach ($codes->chunk(500) as $chunk) {
                $deltaCase = 'CASE `Code`';
                $codesSql  = [];

                foreach ($chunk as $code) {
                    $achat = isset($achatsHier[$code]) ? (float) $achatsHier[$code]->total_achat : 0.0;
                    $vente = isset($ventesHier[$code]) ? (float) $ventesHier[$code]->total_vente  : 0.0;
                    $delta = $achat - $vente;
                    $q     = DB::getPdo()->quote($code);

                    $deltaCase  .= " WHEN {$q} THEN QuantiteStock + {$delta}";
                    $codesSql[] = $q;
                }

                if (empty($codesSql)) continue;

                DB::statement('
                    UPDATE stocks
                    SET QuantiteStock = ' . $deltaCase . ' END,
                        PrixTotal     = (' . $deltaCase . ' END) * PrixU
                    WHERE `Code` IN (' . implode(',', $codesSql) . ')
                ');
            }

            Log::info('SyncStockJob: ' . $codes->count() . ' article(s) mis à jour.');
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncMouvementsHier: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. PrixU = prix du dernier achat d'hier
    //    PrixTotal recalculé en SQL pur — pas de doublon
    // =========================================================================

    private function syncPrixU(Carbon $hier): void
    {
        try {
            $hierDate = $hier->format('Y-m-d');

            $prixHier = DB::table('achats')
                ->whereDate('date', $hierDate)
                ->selectRaw('Code, MAX(PrixU) AS PrixU')
                ->groupBy('Code')
                ->get()
                ->keyBy('Code');

            if ($prixHier->isEmpty()) {
                Log::info('SyncStockJob::syncPrixU: aucun achat hier.');
                return;
            }

            foreach ($prixHier->chunk(500) as $chunk) {
                $prixCase = 'CASE `Code`';
                $codes    = [];

                foreach ($chunk as $code => $row) {
                    $prixU     = (float) $row->PrixU;
                    $q         = DB::getPdo()->quote($code);
                    $prixCase .= " WHEN {$q} THEN {$prixU}";
                    $codes[]   = $q;
                }

                if (empty($codes)) continue;

                // PrixTotal = QuantiteStock * nouveauPrixU en SQL pur
                DB::statement('
                    UPDATE stocks
                    SET PrixU     = ' . $prixCase . ' END,
                        PrixTotal = QuantiteStock * (' . $prixCase . ' END)
                    WHERE `Code` IN (' . implode(',', $codes) . ')
                ');
            }

            Log::info('SyncStockJob::syncPrixU: ' . $prixHier->count() . ' prix mis à jour.');
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
