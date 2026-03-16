<?php

namespace App\Http\Controllers;

use App\Jobs\SyncStockJob;
use App\Models\Stocks;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockController extends Controller
{
    // =========================================================================
    // PAGE PRINCIPALE — dispatch job en arrière-plan + lecture seule
    // =========================================================================

    public function index(Request $request)
    {
        $this->dispatchSyncSiNecessaire();

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
            $q->where('Code',          'like', "%{$search}%")
                ->orWhere('Liblong',     'like', "%{$search}%")
                ->orWhere('fournisseur', 'like', "%{$search}%")
        )
            ->when($fournisseur, fn($q) => $q->where('fournisseur', $fournisseur))
            ->when($maxQte !== '', fn($q) => $q->where('QuantiteStock', '<', (float) $maxQte))
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
            'sync_en_cours'    => !cache()->get('sync_done'),
        ]);
    }

    // =========================================================================
    // UPDATE QUANTITÉ inline — correction manuelle
    // =========================================================================

    public function update(Request $request)
    {
        $request->validate([
            'Code'     => 'required|string',
            'quantite' => 'required|numeric|min:0',
        ]);

        $code     = $request->input('Code');
        $quantite = (float) $request->input('quantite');

        // Recherche insensible à la casse pour compatibilité Windows/WAMP
        $stock = Stocks::whereRaw('BINARY `Code` = ?', [$code])->first()
            ?? Stocks::where('Code', $code)->first();

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => "Article '{$code}' introuvable.",
            ], 404);
        }

        $prixU    = (float) ($stock->PrixU ?? 0);
        $prixtotal = round($quantite * $prixU, 2);

        // Mise à jour directe en SQL pour éviter tout problème de casse
        \Illuminate\Support\Facades\DB::table('stocks')
            ->where('Code', $code)
            ->update([
                'QuantiteStock' => $quantite,
                'PrixTotal'     => $prixtotal,
            ]);

        return response()->json([
            'success'       => true,
            'QuantiteStock' => $quantite,
            'PrixTotal'     => number_format($prixtotal, 2, '.', ''),
            'PrixU'         => number_format($prixU, 2, '.', ''),
        ]);
    }

    // =========================================================================
    // PRIVÉ — anti-boucle : dispatch seulement si cache expiré ET pas déjà en queue
    // =========================================================================

    private function dispatchSyncSiNecessaire(): void
    {
        if (cache()->get('sync_done'))           return;
        if (cache()->get('sync_job_dispatched')) return;

        SyncStockJob::dispatch()->onQueue('stock');

        // Bloquer un nouveau dispatch pendant 35 min (durée max du job)
        cache()->put('sync_job_dispatched', true, now()->addMinutes(35));
    }
}
