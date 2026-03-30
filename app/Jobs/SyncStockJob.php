<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SyncStockJob
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Ce job fait 2 choses dans l'ordre :
 *
 *  1. syncArticlesManquants()
 *     → Insère dans stocks les articles présents dans la table articles
 *       mais absents de stocks (nouveaux articles).
 *     → Resynchronise Liblong et fournisseur pour tous les articles.
 *
 *  2. soustraireVentes($hier)
 *     → Soustrait les quantités vendues (table servmcljournal{Ymd}) du stock.
 *     → PrixTotal recalculé en 2 UPDATE séparés pour éviter le bug MySQL
 *       où le CASE est évalué deux fois avec l'ancienne valeur.
 *
 * NOTE : Les ACHATS ne sont PAS traités ici.
 *   Ils sont appliqués en temps réel par VenteStockService::ajusterStockBulkAvecPrix()
 *   appelé dans AchatController après chaque import.
 *   → Pas de double comptage.
 *
 * PROTECTION CONTRE LES DOUBLONS :
 *   "ventes_sync_{Ymd}" en cache → ventes de cette date déjà soustraites.
 *   Le job refuse de tourner si cette clé existe déjà.
 *
 * UTILISATION :
 *   SyncStockJob::dispatch();               // → Carbon::yesterday()
 *   SyncStockJob::dispatch('2026-03-22');   // → rejoue une date précise
 *
 * CONNEXIONS :
 *   mysql        → stocks, articles
 *   mysql_ventes → servmcljournal{Ymd}
 * ═══════════════════════════════════════════════════════════════════════════
 */
class SyncStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    private const DB_VENTES          = 'mysql_ventes';
    private const LOCK_KEY           = 'sync_stock_running';
    private const LOCK_TTL           = 660;
    private const VENTES_DONE_PREFIX = 'ventes_sync_';

    private ?string $dateOverride;

    public function __construct(?string $date = null)
    {
        $this->dateOverride = $date;
    }

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(): void
    {
        // ── Guard : un seul job à la fois ─────────────────────────────────
        if (Cache::get(self::LOCK_KEY)) {
            Log::warning('SyncStockJob: déjà en cours, abandon.');
            return;
        }
        Cache::put(self::LOCK_KEY, true, self::LOCK_TTL);

        try {
            $hier     = $this->dateOverride
                ? Carbon::parse($this->dateOverride)
                : Carbon::yesterday();
            $hierYmd  = $hier->format('Ymd');
            $cleDone  = self::VENTES_DONE_PREFIX . $hierYmd;

            // ── Guard : ventes de cette date déjà appliquées ──────────────
            if (Cache::get($cleDone)) {
                Log::info("SyncStockJob: ventes du {$hier->format('d/m/Y')} déjà appliquées.");
                return;
            }

            Log::info('SyncStockJob: démarrage pour ' . $hier->format('d/m/Y'));

            // ── Étape 1 : articles manquants ──────────────────────────────
            $this->syncArticlesManquants();

            // ── Étape 2 : soustraire les ventes ───────────────────────────
            $nb = $this->soustraireVentes($hier);

            if ($nb > 0) {
                Cache::put($cleDone, true, now()->addDays(400));
                Cache::put('sync_done_' . $hierYmd, true, now()->addDays(400));
                Log::info("SyncStockJob: {$nb} articles mis à jour pour le {$hier->format('d/m/Y')}.");
            } else {
                // Aucune vente mais on marque quand même pour ne pas repasser
                Cache::put($cleDone, true, now()->addDays(400));
                Cache::put('sync_done_' . $hierYmd, true, now()->addDays(400));
                Log::info("SyncStockJob: aucune vente pour le {$hier->format('d/m/Y')}.");
            }

            Cache::forget('sync_dispatched_' . $hierYmd);
            Log::info('SyncStockJob: terminé.');
        } catch (\Throwable $e) {
            Log::error('SyncStockJob exception: ' . $e->getMessage());
            throw $e;
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    // =========================================================================
    // ÉTAPE 1 : Articles manquants + resync Liblong / fournisseur
    //
    // Chunks de 500 → pas de verrou global sur stocks (évite SQLSTATE 1205).
    // =========================================================================

    private function syncArticlesManquants(): void
    {
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 120');

            $chunkSize = 500;
            $nbIns     = 0;
            $nbUpd     = 0;

            // ── INSERT articles absents de stocks ─────────────────────────
            DB::table('articles')
                ->orderBy('Code')
                ->chunk($chunkSize, function ($articles) use (&$nbIns) {
                    $codes = $articles->pluck('Code')->filter()->all();
                    if (empty($codes)) return;

                    // Codes déjà présents → on ne les réinsère pas
                    $existants = DB::table('stocks')
                        ->whereIn('Code', $codes)
                        ->pluck('Code')
                        ->filter()
                        ->flip()
                        ->all();

                    $inserts = [];
                    foreach ($articles as $a) {
                        if ($a->Code === null) continue;
                        if (!isset($existants[$a->Code])) {
                            $inserts[] = [
                                'Code'          => $a->Code,
                                'Liblong'       => $a->Liblong       ?? '',
                                'fournisseur'   => $a->fournisseur   ?? null,
                                'QuantiteStock' => 0,
                                'PrixU'         => 0,
                                'PrixTotal'     => 0,
                            ];
                        }
                    }

                    if (!empty($inserts)) {
                        DB::table('stocks')->insertOrIgnore($inserts);
                        $nbIns += count($inserts);
                    }
                });

            Log::info("SyncStockJob::syncArticles: {$nbIns} articles insérés.");

            // ── UPDATE Liblong + fournisseur — bulk CASE WHEN ────────────
            // 1 seule requête par chunk de 500 au lieu de 27 000 UPDATE individuels
            DB::table('articles')
                ->orderBy('Code')
                ->chunk($chunkSize, function ($articles) use (&$nbUpd) {
                    $caseLiblong     = 'CASE `Code`';
                    $caseFournisseur = 'CASE `Code`';
                    $codes           = [];

                    foreach ($articles as $a) {
                        if ($a->Code === null) continue;
                        $q                = DB::getPdo()->quote($a->Code);
                        $liblong          = DB::getPdo()->quote($a->Liblong     ?? '');
                        $fournisseur      = $a->fournisseur !== null
                            ? DB::getPdo()->quote($a->fournisseur)
                            : 'NULL';

                        $caseLiblong     .= " WHEN {$q} THEN {$liblong}";
                        $caseFournisseur .= " WHEN {$q} THEN {$fournisseur}";
                        $codes[]          = $q;
                        $nbUpd++;
                    }

                    if (empty($codes)) return;

                    DB::statement('
                        UPDATE stocks
                        SET Liblong     = ' . $caseLiblong     . ' END,
                            fournisseur = ' . $caseFournisseur . ' END
                        WHERE `Code` IN (' . implode(',', $codes) . ')
                    ');
                });

            Log::info("SyncStockJob::syncArticles: {$nbUpd} libellés synchronisés.");
        } catch (\Throwable $e) {
            Log::error('SyncStockJob::syncArticles: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // ÉTAPE 2 : Soustraction des ventes
    //
    // Lit servmcljournal{Ymd}, somme E1 par article.
    // E1 est un CHAR avec virgule française ("0,174" ou "-2").
    // Valeurs négatives = retours → la soustraction augmente le stock.
    //
    // DEUX UPDATE SÉPARÉS pour éviter le bug MySQL :
    //   Dans un seul UPDATE SET a = CASE..., b = CASE... * a,
    //   MySQL évalue les deux CASE indépendamment avec l'ANCIENNE valeur de a.
    //   → PrixTotal utilisait l'ancienne QuantiteStock au lieu de la nouvelle.
    //
    //   Fix : UPDATE 1 → QuantiteStock
    //         UPDATE 2 → PrixTotal = nouvelle QuantiteStock * PrixU
    // =========================================================================

    private function soustraireVentes(Carbon $hier): int
    {
        $tableName = 'servmcljournal' . $hier->format('Ymd');

        if (!Schema::connection(self::DB_VENTES)->hasTable($tableName)) {
            Log::warning("SyncStockJob: table {$tableName} introuvable.");
            return 0;
        }

        // Agréger les ventes par article
        $ventes = DB::connection(self::DB_VENTES)
            ->table($tableName)
            ->selectRaw("
                CAST(idcint AS UNSIGNED) AS Code,
                SUM(
                    CASE
                        WHEN TRIM(E1) REGEXP '^-?[0-9]+([,\\.][0-9]+)?$'
                        THEN CAST(REPLACE(TRIM(E1), ',', '.') AS DECIMAL(10,3))
                        ELSE 0
                    END
                ) AS total_vendu
            ")
            ->groupByRaw('CAST(idcint AS UNSIGNED)')
            ->get()  // pas de HAVING → on traite TOUS les articles vendus, même retours nets
            ->keyBy('Code');

        if ($ventes->isEmpty()) {
            Log::info('SyncStockJob::soustraireVentes: table vide ou aucun idcint valide.');
            return 0;
        }

        $nb = 0;

        foreach ($ventes->chunk(500) as $chunk) {
            $caseQte = 'CASE CAST(`Code` AS UNSIGNED)';
            $inList  = [];

            foreach ($chunk as $code => $row) {
                $totalVendu = round((float) $row->total_vendu, 3);
                $codeInt    = (int) $code;

                $caseQte .= " WHEN {$codeInt} THEN QuantiteStock - ({$totalVendu})";
                $inList[] = $codeInt;
            }

            if (empty($inList)) continue;

            $in = implode(',', $inList);

            // UPDATE 1 : nouvelle QuantiteStock
            DB::statement("
                UPDATE stocks
                SET QuantiteStock = ROUND({$caseQte} END, 3)
                WHERE CAST(`Code` AS UNSIGNED) IN ({$in})
            ");

            // UPDATE 2 : PrixTotal avec la NOUVELLE QuantiteStock déjà en base
            DB::statement("
                UPDATE stocks
                SET PrixTotal = ROUND(QuantiteStock * PrixU, 2)
                WHERE CAST(`Code` AS UNSIGNED) IN ({$in})
            ");

            $nb += count($inList);
        }

        return $nb;
    }

    // =========================================================================
    // Échec
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Cache::forget(self::LOCK_KEY);
        $hierYmd = $this->dateOverride
            ? Carbon::parse($this->dateOverride)->format('Ymd')
            : Carbon::yesterday()->format('Ymd');
        Cache::forget('sync_dispatched_' . $hierYmd);
        Log::error('SyncStockJob échoué: ' . $exception->getMessage());
    }
}
