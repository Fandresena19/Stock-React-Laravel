<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    public $timestamps = false;

    // Correspond exactement à la colonne en base
    protected $primaryKey = 'Code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['Code', 'Liblong', 'fournisseur'];

    /**
     * Relation vers fournisseur par correspondance de nom (pas de FK)
     */
    public function fournisseurRelation()
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur', 'fournisseur');
    }
}
