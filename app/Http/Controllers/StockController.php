<?php

namespace App\Http\Controllers;

use App\Jobs\SyncStockJob;
use App\Models\Stocks;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * StockController
 * ─────────────────────────────────────────────────────────────────────────────
 * index() fait deux choses :
 *   1. Dispatche SyncStockJob dans la queue → calculs en arrière-plan
 *   2. Retourne immédiatement les données actuelles de la table stocks
 *
 * L'utilisateur voit sa page INSTANTANÉMENT.
 * Le worker traite le job en parallèle et met stocks à jour.
 * Au prochain rechargement, les données sont fraîches.
 *
 * COLONNES RÉELLES EN BASE (Windows Server, casse exacte) :
 *   Code, Liblong, fournisseur, QuantiteStock, PrixU, PrixTotal
 * ─────────────────────────────────────────────────────────────────────────────
 */
class StockController extends Controller
{
    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================

    public function index(Request $request)
    {
        // ── 1. Dispatcher le job si le cache est expiré ───────────────────────
        // dispatch() place le job dans la table jobs et retourne immédiatement.
        // Le worker (php artisan queue:work) le récupère et l'exécute en parallèle.
        $this->dispatchSyncSiNecessaire();

        // ── 2. Lire les stocks actuels (simple SELECT) ────────────────────────
        $search      = $request->input('search', '');
        $fournisseur = $request->input('fournisseur', '');
        $maxQte      = $request->input('max_qte', '');

        $fournisseursList = Stocks::whereNotNull('fournisseur')
            ->distinct()
            ->orderBy('fournisseur')
            ->pluck('fournisseur');

        $stocks = Stocks::when(
            $search,
            fn($q) =>
            $q->where('Code',        'like', "%{$search}%")
                ->orWhere('Liblong',   'like', "%{$search}%")
                ->orWhere('fournisseur', 'like', "%{$search}%")
        )
            ->when(
                $fournisseur,
                fn($q) =>
                $q->where('fournisseur', $fournisseur)
            )
            ->when(
                $maxQte !== '',
                fn($q) =>
                $q->where('QuantiteStock', '<', (float) $maxQte)
            )
            ->orderBy('Code')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total_articles'  => Stocks::count(),
            'total_valeur'    => Stocks::sum('PrixTotal'),
            'ruptures'        => Stocks::where('QuantiteStock', '<=', 0)->count(),
            'fournisseurs_nb' => Stocks::whereNotNull('fournisseur')->distinct('fournisseur')->count(),
        ];

        return Inertia::render('stocks/index', [
            'stocks'           => $stocks,
            'fournisseursList' => $fournisseursList,
            'stats'            => $stats,
            'filters'          => compact('search', 'fournisseur') + ['max_qte' => $maxQte],
            // Indique au frontend si une sync est en cours
            'sync_en_cours'    => !$this->toutEstAJour(),
        ]);
    }

    // =========================================================================
    // UPDATE QUANTITÉ inline (correction manuelle)
    // =========================================================================

    public function update(Request $request)
    {
        $request->validate([
            'Code'     => 'required|string',
            'quantite' => 'required|numeric|min:0',
        ]);

        $stock = Stocks::where('Code', $request->Code)->firstOrFail();

        $prixU    = (float) ($stock->PrixU ?? 0);
        $quantite = (float) $request->quantite;

        $stock->update([
            'QuantiteStock' => $quantite,
            'PrixTotal'     => $quantite * $prixU,
        ]);

        return response()->json([
            'success'   => true,
            'prixtotal' => number_format($quantite * $prixU, 2, '.', ''),
            'prixU'     => number_format($prixU, 2, '.', ''),
        ]);
    }

    // =========================================================================
    // PRIVÉ
    // =========================================================================

    private function dispatchSyncSiNecessaire(): void
    {
        $articlesOk = (bool) cache()->get('sync_articles_ok');
        $ventesOk   = (bool) cache()->get('sync_ventes_ok');
        $prixUOk    = (bool) cache()->get('sync_prixu_ok');

        if ($articlesOk && $ventesOk && $prixUOk) return;

        // Vérifier qu'un job identique n'est pas déjà en attente
        // (évite d'empiler des dizaines de jobs si l'utilisateur recharge)
        if (cache()->get('sync_job_dispatched')) return;

        SyncStockJob::dispatch(
            syncArticles: !$articlesOk,
            syncVentes: !$ventesOk,
            syncPrixU: !$prixUOk,
        )->onQueue('stock');

        // Bloquer un nouveau dispatch pendant 30 secondes
        cache()->put('sync_job_dispatched', true, now()->addSeconds(30));
    }

    private function toutEstAJour(): bool
    {
        return (bool) cache()->get('sync_articles_ok')
            && (bool) cache()->get('sync_ventes_ok')
            && (bool) cache()->get('sync_prixu_ok');
    }
}
