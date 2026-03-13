<?php

namespace App\Console\Commands;

use App\Models\Stocks;
use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RecalculerStocks
 *
 * Commande de secours : recalcule TOUT le stock depuis zéro.
 * À utiliser uniquement si la table stocks est désynchronisée
 * (ex : après une migration, un import manuel, ou un bug passé).
 *
 * Usage :
 *   php artisan stocks:recalculer
 *   php artisan stocks:recalculer --dry-run   (simulation sans écriture)
 *
 * En production normale, cette commande ne doit PAS être nécessaire
 * car les Observers et VenteStockService maintiennent le stock en temps réel.
 */
class RecalculerStocks extends Command
{
    protected $signature   = 'stocks:recalculer {--dry-run : Simuler sans écrire en base}';
    protected $description = 'Recalcule tout le stock depuis les achats et les ventes (commande de secours)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('=== Recalcul complet du stock ===');
        if ($dryRun) {
            $this->warn('Mode DRY-RUN : aucune écriture en base.');
        }

        // ── 1. Sync articles → stocks ─────────────────────────────────────────
        $this->info('Étape 1/3 : Synchronisation articles → stocks...');
        $articles      = Article::all();
        $existingCodes = Stocks::pluck('code')->flip()->toArray();
        $toInsert      = [];

        foreach ($articles as $article) {
            if (!array_key_exists((string) $article->Code, $existingCodes)) {
                $toInsert[] = [
                    'code'          => $article->Code,
                    'liblong'       => $article->Liblong,
                    'fournisseur'   => $article->fournisseur,
                    'quantitestock' => 0,
                    'prixU'         => 0,
                    'prixtotal'     => 0,
                ];
            }
        }

        if (!$dryRun && count($toInsert)) {
            foreach (array_chunk($toInsert, 500) as $chunk) {
                DB::table('stocks')->insert($chunk);
            }
        }
        $this->line('  → ' . count($toInsert) . ' nouveaux articles insérés dans stocks.');

        // ── 2. Totaux achats ──────────────────────────────────────────────────
        $this->info('Étape 2/3 : Calcul des totaux achats...');
        $achats = DB::table('achats')
            ->selectRaw('Code, COALESCE(SUM(QuantiteAchat), 0) AS total_achat')
            ->groupBy('Code')
            ->get()
            ->keyBy('Code');

        // ── 3. Totaux ventes (toutes tables dynamiques) ───────────────────────
        $this->info('Étape 3/3 : Calcul des totaux ventes...');
        $database    = DB::getDatabaseName();
        $venteTables = DB::select("
            SELECT t.TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES t
            WHERE t.TABLE_SCHEMA = ?
              AND t.TABLE_NAME LIKE 'servmcljournal%'
              AND (
                  SELECT COUNT(*)
                  FROM INFORMATION_SCHEMA.COLUMNS c
                  WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
                    AND c.TABLE_NAME   = t.TABLE_NAME
                    AND c.COLUMN_NAME IN ('idcint', 'E1')
              ) = 2
        ", [$database]);

        $ventesTotaux = [];
        $bar          = $this->output->createProgressBar(count($venteTables));
        $bar->start();

        foreach ($venteTables as $row) {
            $table = $row->TABLE_NAME;
            $rows  = DB::select("
                SELECT idcint AS Code, COALESCE(SUM(E1), 0) AS total_vente
                FROM `{$table}` GROUP BY idcint
            ");
            foreach ($rows as $r) {
                $ventesTotaux[$r->Code] = ($ventesTotaux[$r->Code] ?? 0) + (float) $r->total_vente;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // ── 4. Mise à jour en bulk ────────────────────────────────────────────
        $stocks   = DB::table('stocks')->select('code', 'prixU')->get();
        $updated  = 0;

        foreach ($stocks->chunk(500) as $chunk) {
            $qteCase   = 'CASE code';
            $totalCase = 'CASE code';
            $codes     = [];

            foreach ($chunk as $stock) {
                $code       = $stock->code;
                $prixU      = (float) ($stock->prixU ?? 0);
                $totalAchat = isset($achats[$code]) ? (float) $achats[$code]->total_achat : 0;
                $totalVente = $ventesTotaux[$code]  ?? 0;
                $newQte     = $totalAchat - $totalVente;
                $newTotal   = $newQte * $prixU;

                $quotedCode = DB::getPdo()->quote($code);
                $qteCase   .= " WHEN {$quotedCode} THEN {$newQte}";
                $totalCase .= " WHEN {$quotedCode} THEN {$newTotal}";
                $codes[]    = $quotedCode;
                $updated++;
            }

            if (empty($codes)) continue;

            $qteCase   .= ' END';
            $totalCase .= ' END';
            $inList     = implode(',', $codes);

            if (!$dryRun) {
                DB::statement("
                    UPDATE stocks
                    SET quantitestock = {$qteCase}, prixtotal = {$totalCase}
                    WHERE code IN ({$inList})
                ");
            }
        }

        $this->info("✅ Recalcul terminé : {$updated} ligne(s) de stock mises à jour.");

        return self::SUCCESS;
    }
}
