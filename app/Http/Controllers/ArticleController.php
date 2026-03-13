<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Fournisseur;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $articles = Article::query()
            ->when(
                $request->search,
                fn($q) => $q
                    ->where('Code', 'like', "%{$request->search}%")
                    ->orWhere('Liblong', 'like', "%{$request->search}%")
            )
            ->orderBy('Code', 'asc')
            ->paginate(20)
            ->through(fn($article) => [
                'Code'        => $article->Code,
                'Liblong'     => $article->Liblong,
                'fournisseur' => $article->fournisseur,
            ])
            ->withQueryString();

        $fournisseurs = Fournisseur::select('id_fournisseur', 'fournisseur')->get();

        return Inertia::render('articles/index', [
            'articles'     => $articles,
            'fournisseurs' => $fournisseurs,
            'filters'      => ['search' => $request->search ?? ''],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            // FIX : on valide 'code' minuscule (ce que le formulaire envoie)
            'code'        => 'required|string|max:255|unique:articles,Code',
            'Liblong'     => 'required|string|max:255',
            'fournisseur' => 'required|string|max:255',
        ]);

        Article::create([
            'Code'        => $request->code,
            'Liblong'     => $request->Liblong,
            'fournisseur' => $request->fournisseur,
        ]);

        return redirect()->back()->with('success', 'Article ajouté avec succès.');
    }

    public function update(Request $request, $code)
    {
        // FIX : on ne fait PAS de route model binding automatique car la PK est une string
        // et Laravel peut échouer à résoudre Article $article selon la config des routes.
        // On cherche manuellement l'article par son Code.
        $article = Article::where('Code', $code)->firstOrFail();

        $request->validate([
            'Liblong'     => 'required|string|max:255',
            'fournisseur' => 'required|string|max:255',
        ]);

        $article->update([
            'Liblong'     => $request->Liblong,
            'fournisseur' => $request->fournisseur,
        ]);

        return redirect()->back()->with('success', 'Article mis à jour.');
    }

    public function destroy($code)
    {
        // FIX : même raison, recherche manuelle par Code string
        $article = Article::where('Code', $code)->firstOrFail();
        $article->delete();

        return redirect()->back()->with('success', 'Article supprimé.');
    }
}
