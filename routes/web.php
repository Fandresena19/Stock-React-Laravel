<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\AchatController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;


Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('articles', ArticleController::class);

    Route::resource('fournisseurs', FournisseurController::class);

    // Achats
    Route::prefix('achats')->name('achats.')->group(function () {
        Route::get('/',             [AchatController::class, 'index'])->name('index');
        Route::post('/import',      [AchatController::class, 'import'])->name('import');
        Route::post('/import-auto', [AchatController::class, 'importAuto'])->name('import-auto');
        Route::delete('/{id}',      [AchatController::class, 'destroy'])->name('destroy');
    });

    // Stocks
    Route::prefix('stocks')->name('stocks.')->group(function () {
        Route::get('/',           [StockController::class, 'index'])->name('index');
        Route::post('/update',    [StockController::class, 'update'])->name('update');
        Route::get('/export',     [StockController::class, 'export'])->name('export');
        Route::get('/sync-status', [StockController::class, 'syncStatus'])->name('sync-status');
        // conserver si existante
        Route::post('/import-init', [StockController::class, 'importInit'])->name('import-init');
    });

    // Ventes
    Route::get('/ventes',        [VenteController::class, 'index'])->name('ventes.index');
    Route::get('/ventes/dates',  [VenteController::class, 'availableDates'])->name('ventes.dates');
});

require __DIR__ . '/settings.php';
