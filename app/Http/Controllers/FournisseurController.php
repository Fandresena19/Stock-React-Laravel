<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FournisseurController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');

        $fournisseurs = Fournisseur::when(
            $search,
            fn($q) => $q->where('fournisseur', 'like', "%{$search}%")
        )
            ->orderBy('fournisseur')
            ->paginate(50)
            ->withQueryString(); // BUG FIX : déjà présent, correct

        return Inertia::render('fournisseurs/index', [
            'fournisseurs' => $fournisseurs,
            'filters'      => ['search' => $search],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // BUG FIX : méthode était complètement vide
        $request->validate([
            'fournisseur' => 'required|string|max:255|unique:fournisseurs,fournisseur',
        ]);

        Fournisseur::create([
            'fournisseur' => $request->fournisseur,
        ]);

        return redirect()->back()->with('success', 'Fournisseur ajouté avec succès.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Fournisseur $fournisseur)
    {
        // BUG FIX : méthode était vide
        $request->validate([
            'fournisseur' => 'required|string|max:255|unique:fournisseurs,fournisseur,' . $fournisseur->id_fournisseur . ',id_fournisseur',
        ]);

        $fournisseur->update([
            'fournisseur' => $request->fournisseur,
        ]);

        return redirect()->back()->with('success', 'Fournisseur mis à jour.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fournisseur $fournisseur)
    {
        // BUG FIX : méthode était vide
        $fournisseur->delete();

        return redirect()->back()->with('success', 'Fournisseur supprimé.');
    }
}
