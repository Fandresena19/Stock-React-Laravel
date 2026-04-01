<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaires', function (Blueprint $table) {
            $table->id();
            $table->date('date_inventaire');
            $table->enum('type', ['total', 'partiel']);
            $table->string('filename')->nullable();
            $table->integer('nb_lignes_modifiees')->default(0);
            $table->integer('nb_lignes_ignorees')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaires');
    }
};
