<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportedFile extends Model
{
    protected $table = 'imported_files';

    protected $fillable = [
        'filename',
        'path',
        'imported_at',
        'total_rows',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];
}
