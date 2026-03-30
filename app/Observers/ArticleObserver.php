<?php

namespace App\Observers;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

class ArticleObserver
{
    // Nouvel article → INSERT direct dans stocks (rapide, pas d'Eloquent)
    public function created(Article $article): void
    {
        DB::table('stocks')->insertOrIgnore([
            'Code'          => $article->Code,
            'Liblong'       => $article->Liblong,
            'fournisseur'   => $article->fournisseur,
            'QuantiteStock' => 0,
            'PrixU'         => 0,
            'PrixTotal'     => 0,
        ]);
    }

    // Article modifié → UPDATE direct (rapide)
    public function updated(Article $article): void
    {
        DB::table('stocks')->where('Code', $article->Code)->update([
            'Liblong'     => $article->Liblong,
            'fournisseur' => $article->fournisseur,
        ]);
    }

    // Article supprimé → NE PAS supprimer le stock (historique conservé)
    // Décommenter pour supprimer le stock aussi :
    // public function deleted(Article $article): void
    // {
    //     DB::table('stocks')->where('Code', $article->Code)->delete();
    // }
}
