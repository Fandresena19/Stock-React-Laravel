<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stocks extends Model
{
    protected $table      = 'stocks';
    protected $primaryKey = 'Code';
    public    $incrementing = false;
    protected $keyType    = 'string';
    public    $timestamps = false;

    // Noms EXACTS des colonnes MySQL dans la table 'stocks'
    protected $fillable = [
        'Code',
        'Liblong',
        'fournisseur',
        'QuantiteStock',
        'PrixU',
        'PrixTotal',
    ];

    protected $casts = [
        'QuantiteStock' => 'float',
        'PrixU'         => 'float',
        'PrixTotal'     => 'float',
    ];

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) return $query;
        return $query->where('Code',         'like', "%{$search}%")
            ->orWhere('Liblong',    'like', "%{$search}%")
            ->orWhere('fournisseur', 'like', "%{$search}%");
    }

    public function scopeRuptures($query)
    {
        return $query->where('QuantiteStock', '<=', 0);
    }
}
