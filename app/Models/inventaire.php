<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventaire extends Model
{
    public    $timestamps  = false;
    protected $fillable    = [
        'date_inventaire',
        'type',
        'filename',
        'nb_lignes_modifiees',
        'nb_lignes_ignorees',
        'notes',
        'created_at',
    ];
    protected $casts = [
        'date_inventaire' => 'date',
        'created_at'      => 'datetime',
    ];
}
