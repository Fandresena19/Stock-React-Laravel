<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────────
// IMPORT AUTOMATIQUE DES ACHATS — tous les matins à 06h00
// Traite les fichiers Excel/CSV modifiés hier dans ACHATS_WATCH_FOLDER
//
// Pour activer, configurer UNE SEULE fois dans Windows Task Scheduler :
//   Programme : C:\wamp64\bin\php\php8.2.13\php.exe
//   Arguments : C:\wamp64\www\StockN2\artisan schedule:run
//   Répétition : toutes les 1 minute
// ─────────────────────────────────────────────────────────────────────────────
Schedule::command('import:achat-auto')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();
