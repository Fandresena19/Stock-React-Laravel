<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VenteStockService
 * ─────────────────────────────────────────────────────────────────────────────
 * Mouvements immédiats (pas de job, pas de délai) :
 *   - Import Excel     → ajusterStockBulkAvecPrix()  (appelé par AchatController)
 *   - Suppression achat → ajusterStockVente()         (appelé par AchatController)
 *   - Achat unitaire   → ajusterStockAchat()          (appelé par AchatObserver si Eloquent)
 *
 * Chaque méthode fait 1 seul UPDATE ciblé sur la/les ligne(s) concernée(s).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class VenteStockService
{
    // =========================================================================
    // VENTE — diminuer le stock (1 UPDATE)
    // =========================================================================

    public function ajusterStockVente(string $code, float $quantite): void
    {
        $this->ajuster($code, $quantite, '-');
    }

    // =========================================================================
    // ANNULATION VENTE — remettre en stock
    // =========================================================================

    public function annulerVente(string $code, float $quantite): void
    {
        $this->ajuster($code, $quantite, '+');
    }

    // =========================================================================
    // ACHAT UNITAIRE — augmenter stock + màj PrixU si plus récent
    // =========================================================================

    public function ajusterStockAchat(
        string $code,
        float  $quantite,
        float  $nouveauPrixU,
        string $dateAchat
    ): void {
        $derniereDateEnBase = DB::table('achats')
            ->where('Code', $code)
            ->max('date');

        if ($derniereDateEnBase === null || $dateAchat >= $derniereDateEnBase) {
            DB::table('stocks')
                ->where('Code', $code)
                ->update([
                    'QuantiteStock' => DB::raw("QuantiteStock + {$quantite}"),
                    'PrixU'         => $nouveauPrixU,
                    'PrixTotal'     => DB::raw("(QuantiteStock + {$quantite}) * {$nouveauPrixU}"),
                ]);
        } else {
            $this->ajuster($code, $quantite, '+');
        }
    }

    // =========================================================================
    // BULK ACHATS avec prix (import Excel)
    // Un seul UPDATE CASE WHEN pour tous les articles → ~5ms pour 500 articles
    //
    // @param array $mouvements [
    //   ['code' => 'ART01', 'quantite' => 5.0, 'prixU' => 150.0, 'date' => '2026-03-12'],
    // ]
    // =========================================================================

    public function ajusterStockBulkAvecPrix(array $mouvements): void
    {
        if (empty($mouvements)) return;

        // Grouper par code, sommer quantités, garder PrixU du plus récent
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

            $in = implode(',', $codes);
            DB::statement("
                UPDATE stocks
                SET QuantiteStock = {$qteCase} END,
                    PrixU         = {$prixCase} END,
                    PrixTotal     = {$totalCase} END
                WHERE `Code` IN ({$in})
            ");
        }

        // Invalider le cache PrixU pour que le prochain job le rafraîchisse
        cache()->forget('sync_prixu_ok');
    }

    // =========================================================================
    // PRIVÉ — 1 UPDATE ciblé sur 1 ligne
    // =========================================================================

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
