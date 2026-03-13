<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fournisseur extends Model
{
    protected $primaryKey = 'id_fournisseur';
    protected $keyType    = 'int';
    public $incrementing  = true;
    protected $table      = 'fournisseurs';
    public $timestamps    = false;

    protected $fillable = ['fournisseur']; // BUG FIX : retiré 'id_fournisseur' (auto-increment, ne doit pas être fillable)

    /**
     * Les articles liés à ce fournisseur.
     * BUG FIX : la FK était 'id_fournisseur' → corrigée en 'fournisseur'/'fournisseur'
     * car Article n'a pas de colonne id_fournisseur, la relation se fait par le nom
     */
    public function articles()
    {
        return $this->hasMany(Article::class, 'fournisseur', 'fournisseur');
    }
}
