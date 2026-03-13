<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VenteStockService
 * Mouvements IMMÉDIATS (pas de job, instantané) :
 *   - Import Excel      → ajusterStockBulkAvecPrix()
 *   - Suppression achat → ajusterStockVente()
 */
class VenteStockService
{
    // Diminuer le stock (suppression achat)
    public function ajusterStockVente(string $code, float $quantite): void
    {
        $this->ajuster($code, $quantite, '-');
    }

    // Augmenter le stock (annulation vente)
    public function annulerVente(string $code, float $quantite): void
    {
        $this->ajuster($code, $quantite, '+');
    }

    /**
     * Bulk import Excel — 1 seul UPDATE CASE WHEN pour tous les articles
     * @param array $mouvements [['code'=>'X','quantite'=>5.0,'prixU'=>150.0,'date'=>'2026-03-12'],...]
     */
    public function ajusterStockBulkAvecPrix(array $mouvements): void
    {
        if (empty($mouvements)) return;

        $totaux = [];
        foreach ($mouvements as $m) {
            $code = $m['code'];
            if (!isset($totaux[$code])) {
                $totaux[$code] = ['quantite' => 0.0, 'prixU' => (float) $m['prixU'], 'date' => $m['date']];
            }
            $totaux[$code]['quantite'] += (float) $m['quantite'];
            if ($m['date'] >= $totaux[$code]['date']) {
                $totaux[$code]['prixU'] = (float) $m['prixU'];
                $totaux[$code]['date']  = $m['date'];
            }
        }

        foreach (array_chunk($totaux, 500, true) as $chunk) {
            $qteCase   = 'CASE `Code`';
            $prixCase  = 'CASE `Code`';
            $totalCase = 'CASE `Code`';
            $codes     = [];

            foreach ($chunk as $code => $data) {
                $q     = DB::getPdo()->quote($code);
                $qte   = $data['quantite'];
                $prixU = $data['prixU'];

                $qteCase   .= " WHEN {$q} THEN QuantiteStock + {$qte}";
                $prixCase  .= " WHEN {$q} THEN {$prixU}";
                $totalCase .= " WHEN {$q} THEN (QuantiteStock + {$qte}) * {$prixU}";
                $codes[]    = $q;
            }

            DB::statement('
                UPDATE stocks
                SET QuantiteStock = ' . $qteCase . ' END,
                    PrixU         = ' . $prixCase . ' END,
                    PrixTotal     = ' . $totalCase . ' END
                WHERE `Code` IN (' . implode(',', $codes) . ')
            ');
        }

        cache()->forget('sync_done');
    }

    private function ajuster(string $code, float $quantite, string $op): void
    {
        $updated = DB::table('stocks')
            ->where('Code', $code)
            ->update([
                'QuantiteStock' => DB::raw("QuantiteStock {$op} {$quantite}"),
                'PrixTotal'     => DB::raw("(QuantiteStock {$op} {$quantite}) * PrixU"),
            ]);

        if (!$updated) {
            Log::warning("VenteStockService: Code '{$code}' introuvable dans stocks.");
        }
    }
}
