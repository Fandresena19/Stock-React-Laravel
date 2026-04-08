<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\AchatController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\InventaireController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;


Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('articles', ArticleController::class);

    Route::resource('fournisseurs', FournisseurController::class);

    //Achat
    Route::prefix('achats')->name('achats.')->group(function () {
        Route::get('/',            [AchatController::class, 'index'])->name('index');
        Route::post('/import',     [AchatController::class, 'import'])->name('import');
        Route::post('/import-auto', [AchatController::class, 'importAuto'])->name('import-auto');
        Route::delete('/{id}',     [AchatController::class, 'destroy'])->name('destroy');
    });

    // ── Stocks ────────────────────────────────────────────────────────────────────
    Route::get('/stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::post('/stocks/update', [StockController::class, 'update'])->name('stocks.update');
    Route::get('/stocks/export', [StockController::class, 'export'])->name('stocks.export');

    // ── Ventes (AJAX uniquement — pas de page dédiée) ────────────────────────────
    Route::get('/ventes', [VenteController::class, 'index'])->name('ventes.index');
    Route::get('/ventes/dates', [VenteController::class, 'availableDates'])->name('ventes.dates');

    // ── Inventaire ────────────────────────────────────────────────────────────────
    Route::prefix('inventaire')->name('inventaire.')->group(function () {
        Route::get('/',         [InventaireController::class, 'index'])->name('index');
        Route::post('/import',  [InventaireController::class, 'import'])->name('import');
        Route::delete('/{id}',  [InventaireController::class, 'destroy'])->name('destroy');
    });
});

require __DIR__ . '/settings.php';
