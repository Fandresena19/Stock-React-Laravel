<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table imported_row_hashes
 *
 * Stocke le hash SHA-256 de chaque ligne importée, lié au fichier source.
 * Permet de savoir exactement quelles lignes d'un fichier ont déjà été insérées,
 * indépendamment de la date — même si le fichier est ré-importé plus tard.
 *
 * php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_row_hashes', function (Blueprint $table) {
            $table->unsignedBigInteger('imported_file_id');
            $table->char('row_hash', 64);           // SHA-256 = 64 caractères hex

            // Clé primaire composite : un hash ne peut apparaître qu'une fois par fichier
            $table->primary(['imported_file_id', 'row_hash']);

            $table->foreign('imported_file_id')
                ->references('id')
                ->on('imported_files')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_row_hashes');
    }
};
