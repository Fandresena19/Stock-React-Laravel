<?php

namespace App\Http\Middleware;

use App\Services\VenteStockService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * StockSyncMiddleware
 * ─────────────────────────────────────────────────────────────────────────────
 * PRINCIPE :
 *   handle()    → laisse passer la requête, page envoyée immédiatement ⚡
 *   terminate() → appelé par PHP/IIS APRÈS l'envoi de la réponse HTTP
 *                 Aucun impact sur la vitesse perçue par l'utilisateur.
 *
 * RÔLE :
 *   Synchroniser les ventes du logiciel de caisse externe (tables servmcljournal*)
 *   qui ne passent pas par Eloquent et ne peuvent pas avoir d'Observer.
 *
 * FRÉQUENCES (protégées par cache) :
 *   syncVentesStock()    → toutes les 5 minutes (tables modifiées seulement)
 *   syncArticlesManquants() → toutes les 5 minutes
 *
 * ENREGISTREMENT (bootstrap/app.php) :
 *   $middleware->alias(['stock.sync' => StockSyncMiddleware::class]);
 *
 * UTILISATION (routes/web.php) :
 *   Route::get('/dashboard', ...)->middleware(['auth', 'stock.sync']);
 * ─────────────────────────────────────────────────────────────────────────────
 */
class StockSyncMiddleware
{
    public function __construct(private VenteStockService $stockService) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request); // Page envoyée immédiatement ⚡
    }

    /**
     * Appelé automatiquement APRÈS l'envoi de la réponse.
     * Fonctionne nativement sous IIS + PHP FastCGI (Windows Server).
     */
    public function terminate(Request $request, Response $response): void
    {
        $ventesOk   = (bool) cache()->get('sync_ventes_ok');
        $articlesOk = (bool) cache()->get('sync_articles_ok');

        if ($ventesOk && $articlesOk) return;

        try {
            if (!$articlesOk) $this->syncArticlesManquants();
            if (!$ventesOk)   $this->syncVentesStock();
        } catch (\Throwable $e) {
            Log::error('StockSyncMiddleware: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 1. Articles présents dans articles mais absents de stocks
    //    + sync Liblong/fournisseur (1 bulk UPDATE)
    //    Cache : 5 minutes
    // =========================================================================

    private function syncArticlesManquants(): void
    {
        try {
            // Insérer les manquants
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
                Log::info('StockSync: ' . $missing->count() . ' article(s) insérés dans stocks.');
            }

            // Sync Liblong + fournisseur en 1 requête
            DB::statement("
                UPDATE stocks s
                INNER JOIN articles a ON s.Code = a.Code
                SET s.Liblong     = a.Liblong,
                    s.fournisseur = a.fournisseur
            ");

            cache()->put('sync_articles_ok', true, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::error('StockSync::syncArticles: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 2. Ventes depuis les 200+ tables servmcljournal*
    //    Intelligent : traite UNIQUEMENT les tables dont UPDATE_TIME a changé
    //    → Avec 200 tables non modifiées = 0 recalcul, juste une lecture cache
    //    Cache : 5 minutes
    // =========================================================================

    private function syncVentesStock(): void
    {
        try {
            $database = DB::getDatabaseName();

            // Récupérer toutes les tables ventes avec leur date de modification
            $venteTables = DB::select("
                SELECT t.TABLE_NAME, t.UPDATE_TIME
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
                cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
                return;
            }

            // Filtrer : seulement les tables dont UPDATE_TIME a changé
            $tablesModifiees = array_filter(
                $venteTables,
                fn($row) => cache()->get('vente_chk_' . $row->TABLE_NAME) !== (string) $row->UPDATE_TIME
            );

            if (empty($tablesModifiees)) {
                cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
                return;
            }

            Log::info('StockSync::syncVentes: ' . count($tablesModifiees) . ' table(s) modifiée(s) / ' . count($venteTables));

            // Agréger les ventes de TOUTES les tables (total cohérent)
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
                    Log::warning('StockSync: table ' . $row->TABLE_NAME . ' ignorée — ' . $e->getMessage());
                }
            }

            // Totaux achats par code (1 requête)
            $achats = DB::table('achats')
                ->selectRaw('Code, COALESCE(SUM(QuantiteAchat), 0) AS total_achat')
                ->groupBy('Code')
                ->get()
                ->keyBy('Code');

            // Bulk UPDATE stocks par chunks de 500
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
                    $totalCase .= " WHEN {$q} THEN " . ($newQte * $prixU);
                    $codes[]    = $q;
                }

                if (empty($codes)) continue;

                $in = implode(',', $codes);
                DB::statement("
                    UPDATE stocks
                    SET QuantiteStock = {$qteCase} END,
                        PrixTotal     = {$totalCase} END
                    WHERE `Code` IN ({$in})
                ");
            }

            // Marquer les tables modifiées comme traitées
            foreach ($tablesModifiees as $row) {
                cache()->put(
                    'vente_chk_' . $row->TABLE_NAME,
                    (string) $row->UPDATE_TIME,
                    now()->addDays(7)
                );
            }

            cache()->put('sync_ventes_ok', true, now()->addMinutes(5));
        } catch (\Throwable $e) {
            Log::error('StockSync::syncVentes: ' . $e->getMessage());
        }
    }
}
