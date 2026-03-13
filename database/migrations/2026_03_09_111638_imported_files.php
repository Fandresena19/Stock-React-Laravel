<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_files', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique(); // nom du fichier — clé de déduplication
            $table->string('path')->nullable();   // chemin complet au moment de l'import
            $table->integer('total_rows')->default(0); // nb de lignes insérées au total
            $table->timestamp('imported_at')->nullable(); // dernière date d'import
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_files');
    }
};
