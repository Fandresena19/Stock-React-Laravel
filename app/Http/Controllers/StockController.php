<?php

namespace App\Http\Controllers;

use App\Exports\StockExport;
use App\Jobs\SyncStockJob;
use App\Models\Stocks;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class StockController extends Controller
{
    private const SORT_ALLOWED = ['Code', 'fournisseur', 'Liblong', 'QuantiteStock', 'PrixTotal'];

    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================

    public function index(Request $request)
    {
        $this->dispatchSyncSiNecessaire();

        $search      = $request->input('search', '');
        $fournisseur = $request->input('fournisseur', '');
        $maxQte      = $request->input('max_qte', '');
        $sortBy      = in_array($request->input('sort_by'), self::SORT_ALLOWED)
            ? $request->input('sort_by')
            : 'Code';

        $fournisseursList = Stocks::select('fournisseur')
            ->distinct()
            ->whereNotNull('fournisseur')
            ->orderBy('fournisseur')           // tri croissant A→Z
            ->pluck('fournisseur')
            ->values();

        $stocks = Stocks::when(
            $search,
            fn($q) =>
            $q->where('Code',         'like', "%{$search}%")
                ->orWhere('Liblong',     'like', "%{$search}%")
                ->orWhere('fournisseur', 'like', "%{$search}%")
        )
            ->when($fournisseur, fn($q) => $q->where('fournisseur', $fournisseur))
            // FIX : filtre quantité actif indépendamment du fournisseur
            ->when(
                $maxQte !== '',
                fn($q) => $q->where('QuantiteStock', '<', (float) $maxQte)
            )
            // Tri principal croissant + tri secondaire stable par Code
            ->orderBy($sortBy, 'asc')
            ->when(
                $sortBy !== 'Code',
                fn($q) => $q->orderBy('Code', 'asc')
            )
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total_articles'   => DB::table('articles')->count(),
            'total_qte_stock'  => Stocks::sum('QuantiteStock'),
            'total_valeur'     => Stocks::sum('PrixTotal'),
            'ruptures'         => Stocks::where('QuantiteStock', '<=', 0)->count(),
            'fournisseurs_nb'  => Stocks::whereNotNull('fournisseur')->distinct('fournisseur')->count(),
        ];

        $hier = Carbon::yesterday()->format('Ymd');

        return Inertia::render('stocks/index', [
            'stocks'           => $stocks,
            'fournisseursList' => $fournisseursList,
            'stats'            => $stats,
            'filters'          => compact('search', 'fournisseur') + ['max_qte' => $maxQte, 'sort_by' => $sortBy],
            'sync_en_cours'    => !Cache::get('sync_done_' . $hier),
        ]);
    }

    // =========================================================================
    // STATUT SYNC — polling JSON depuis le frontend
    // =========================================================================

    public function syncStatus()
    {
        $hier    = Carbon::yesterday()->format('Ymd');
        $running = (bool) Cache::get('sync_stock_running');
        $done    = (bool) Cache::get('sync_done_' . $hier);

        return response()->json([
            'status' => match (true) {
                $done    => 'done',
                $running => 'running',
                default  => 'pending',
            },
        ]);
    }

    // =========================================================================
    // UPDATE QUANTITÉ inline
    // =========================================================================

    public function update(Request $request)
    {
        $request->validate([
            'Code'     => 'required|string',
            'quantite' => 'required|numeric',
        ]);

        $code     = $request->input('Code');
        $quantite = (float) $request->input('quantite');

        $stock = Stocks::where('Code', $code)->first();

        if (!$stock) {
            return response()->json(['success' => false, 'message' => 'Article introuvable.'], 404);
        }

        $prixU     = (float) ($stock->PrixU ?? 0);
        $prixTotal = round($quantite * $prixU, 2);

        DB::table('stocks')->where('Code', $code)->update([
            'QuantiteStock' => $quantite,
            'PrixTotal'     => $prixTotal,
        ]);

        return response()->json([
            'success'   => true,
            'prixtotal' => number_format($prixTotal, 2, '.', ''),
            'prixU'     => number_format($prixU,     2, '.', ''),
        ]);
    }

    // =========================================================================
    // EXPORT EXCEL
    // =========================================================================

    public function export(Request $request)
    {
        $search      = $request->input('search', '');
        $fournisseur = $request->input('fournisseur', '');
        $maxQte      = $request->input('max_qte', '');
        $filename    = 'stock_' . now()->format('Ymd_His') . '.xlsx';

        set_time_limit(0);
        ini_set('memory_limit', '512M');

        return Excel::download(
            new StockExport($search, $fournisseur, $maxQte),
            $filename,
            \Maatwebsite\Excel\Excel::XLSX,
        );
    }

    // =========================================================================
    // PRIVÉ
    // =========================================================================

    private function dispatchSyncSiNecessaire(): void
    {
        $hier          = Carbon::yesterday()->format('Ymd');
        $cleDone       = 'ventes_sync_'     . $hier;
        $cleDispatched = 'sync_dispatched_' . $hier;

        if (Cache::get($cleDone))       return;
        if (Cache::get($cleDispatched)) return;
        if (Cache::get('sync_stock_running')) return;

        Cache::put($cleDispatched, true, Carbon::tomorrow()->endOfDay());
        SyncStockJob::dispatch();
    }
}
