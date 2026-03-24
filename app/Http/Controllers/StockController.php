<?php

namespace App\Http\Controllers;

use App\Exports\StockExport;
use App\Models\Stocks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class StockController extends Controller
{
    // =========================================================================
    // PAGE PRINCIPALE
    // =========================================================================

    public function index(Request $request)
    {
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
                ->orWhere('Liblong',    'like', "%{$search}%")
                ->orWhere('fournisseur', 'like', "%{$search}%")
        )
            ->when($fournisseur, fn($q) => $q->where('fournisseur', $fournisseur))
            ->when($maxQte !== '', fn($q) => $q->where('QuantiteStock', '<', (float) $maxQte))
            ->orderBy('Code')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total_articles'  => DB::table('articles')->count(),
            'total_valeur'    => Stocks::sum('PrixTotal'),
            'ruptures'        => Stocks::where('QuantiteStock', '<=', 0)->count(),
            'fournisseurs_nb' => Stocks::whereNotNull('fournisseur')->distinct('fournisseur')->count(),
        ];

        return Inertia::render('stocks/index', [
            'stocks'           => $stocks,
            'fournisseursList' => $fournisseursList,
            'stats'            => $stats,
            'filters'          => compact('search', 'fournisseur') + ['max_qte' => $maxQte],
        ]);
    }

    // =========================================================================
    // UPDATE QUANTITÉ inline
    // =========================================================================

    public function update(Request $request)
    {
        $request->validate([
            'Code'     => 'required|string',
            'quantite' => 'required|numeric|min:0',
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
            'prixU'     => number_format($prixU, 2, '.', ''),
        ]);
    }

    // =========================================================================
    // EXPORT EXCEL — tout le stock (ou filtré si filtres actifs)
    // =========================================================================

    public function export(Request $request)
    {
        $search      = $request->input('search', '');
        $fournisseur = $request->input('fournisseur', '');
        $maxQte      = $request->input('max_qte', '');

        $filename = 'stock_' . now()->format('Ymd') . '.xlsx';

        return Excel::download(
            new StockExport($search, $fournisseur, $maxQte),
            $filename
        );
    }
}
