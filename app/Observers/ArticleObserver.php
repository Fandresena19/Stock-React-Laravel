<?php

namespace App\Observers;

use App\Models\Article;
use App\Models\Stocks;

class ArticleObserver
{
    // Nouvel article → créer ligne dans stocks avec quantité 0
    public function created(Article $article): void
    {
        Stocks::firstOrCreate(
            ['Code' => $article->Code],
            [
                'Liblong'       => $article->Liblong,
                'fournisseur'   => $article->fournisseur,
                'QuantiteStock' => 0,
                'PrixU'         => 0,
                'PrixTotal'     => 0,
            ]
        );
    }

    // Article modifié → sync Liblong + fournisseur dans stocks
    public function updated(Article $article): void
    {
        Stocks::where('Code', $article->Code)->update([
            'Liblong'     => $article->Liblong,
            'fournisseur' => $article->fournisseur,
        ]);
    }

    public function deleted(Article $article): void
    {
        // Conserver le stock pour l'historique
        // Décommenter pour supprimer :
        // Stocks::where('Code', $article->Code)->delete();
    }
}
