<?php

namespace App\Console\Commands;

use App\Imports\AchatsImport;
use App\Services\VenteStockService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

/**
 * ImportAchatsAuto
 * ─────────────────────────────────────────────────────────────────────────────
 * Importe automatiquement les fichiers Excel/CSV du dossier surveillé.
 * Traite uniquement les fichiers dont la date de modification = hier.
 *
 * UTILISATION :
 *   php artisan import:achat-auto
 *   php artisan import:achat-auto --date=2026-03-11   (date manuelle)
 *   php artisan import:achat-auto --all               (tous les fichiers sans filtre de date)
 *
 * AUTOMATISER (tâche planifiée) :
 *   Ajouter dans routes/console.php :
 *   Schedule::command('import:achat-auto')->dailyAt('06:00');
 *
 * DOSSIER SURVEILLÉ (configurable dans .env) :
 *   ACHATS_WATCH_FOLDER=D:\Stage\Achat
 *   → Sous-dossier calculé : ACHAT {année}\{MOIS} {année}
 *   → Exemple : D:\Stage\Achat\ACHAT 2026\MARS 2026
 * ─────────────────────────────────────────────────────────────────────────────
 */
class ImportAchatsAuto extends Command
{
    protected $signature = 'import:achat-auto
                            {--date= : Date spécifique (format Y-m-d). Par défaut = hier.}
                            {--all   : Importer tous les fichiers sans filtre de date.}';

    protected $description = 'Importe automatiquement les achats Excel/CSV du dossier surveillé (fichiers modifiés hier)';

    private const MOIS = [
        1  => 'JANVIER',
        2  => 'FEVRIER',
        3  => 'MARS',
        4  => 'AVRIL',
        5  => 'MAI',
        6  => 'JUIN',
        7  => 'JUILLET',
        8  => 'AOUT',
        9  => 'SEPTEMBRE',
        10 => 'OCTOBRE',
        11 => 'NOVEMBRE',
        12 => 'DECEMBRE',
    ];

    public function __construct(private VenteStockService $stockService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $watchFolder = rtrim(env('ACHATS_WATCH_FOLDER', storage_path('app/achats_auto')), '/\\');

        // Date de référence
        if ($this->option('date')) {
            $refDate = Carbon::parse($this->option('date'));
        } else {
            $refDate = Carbon::yesterday();
        }

        $monthFolder = $watchFolder
            . DIRECTORY_SEPARATOR . 'ACHAT ' . $refDate->year
            . DIRECTORY_SEPARATOR . self::MOIS[$refDate->month] . ' ' . $refDate->year;

        $this->info("📁 Dossier : {$monthFolder}");

        if (!File::exists($monthFolder)) {
            $this->error("Dossier introuvable : {$monthFolder}");
            $this->line("Vérifiez ACHATS_WATCH_FOLDER dans .env");
            return Command::FAILURE;
        }

        // Dossier ARCHIVE
        $archiveFolder = $monthFolder . DIRECTORY_SEPARATOR . 'ARCHIVE';
        if (!File::exists($archiveFolder)) {
            File::makeDirectory($archiveFolder, 0755, true);
        }

        // Lister les fichiers Excel/CSV
        $allFiles = collect(File::files($monthFolder))
            ->filter(fn($f) => in_array(strtolower($f->getExtension()), ['xlsx', 'xls', 'csv']));

        if ($allFiles->isEmpty()) {
            $this->warn("Aucun fichier Excel/CSV dans : {$monthFolder}");
            return Command::SUCCESS;
        }

        $this->info("🔍 {$allFiles->count()} fichier(s) trouvé(s).");

        $totalNew   = 0;
        $totalSkip  = 0;
        $processed  = [];
        $ignored    = [];
        $errors     = [];
        $mouvements = [];
        $importAll  = $this->option('all');

        foreach ($allFiles as $file) {
            $filename = $file->getFilename();
            $realPath = $file->getRealPath();
            $mtime    = Carbon::createFromTimestamp($file->getMTime());

            // Filtre date de modification (sauf si --all)
            if (!$importAll && !$mtime->isSameDay($refDate)) {
                $ignored[] = $filename;
                $this->line("  ⏭  Ignoré (date ≠ {$refDate->format('d/m/Y')}) : {$filename}");
                continue;
            }

            $importDate = $mtime->format('Y-m-d');

            $this->line("  📄 Traitement : {$filename} (date achat : {$importDate})");

            try {
                $importer = new AchatsImport($filename, $realPath, $importDate);
                Excel::import($importer, $realPath);

                $new  = $importer->getNewRowsCount();
                $skip = $importer->getSkippedRowsCount();

                $totalNew  += $new;
                $totalSkip += $skip;
                $processed[] = $filename;

                $this->line("     ✅ {$new} ligne(s) insérée(s), {$skip} doublon(s) ignoré(s).");

                foreach ($importer->getInsertedRows() as $row) {
                    $mouvements[] = [
                        'code'     => $row['Code'],
                        'quantite' => (float) $row['QuantiteAchat'],
                        'prixU'    => (float) $row['PrixU'],
                        'date'     => $importDate,
                    ];
                }

                // Archiver le fichier
                $dest = $archiveFolder . DIRECTORY_SEPARATOR . $mtime->format('Ymd') . '_' . $filename;
                File::copy($realPath, $dest);
            } catch (\Throwable $e) {
                $errors[] = $filename . ' : ' . $e->getMessage();
                $this->error("     ❌ Erreur : {$e->getMessage()}");
                Log::error('import:achat-auto', ['file' => $filename, 'message' => $e->getMessage()]);
            }
        }

        // Mettre à jour le stock immédiatement après l'import
        if (!empty($mouvements)) {
            $this->line('');
            $this->info("⚙  Mise à jour du stock ({$totalNew} mouvement(s))…");
            $this->stockService->ajusterStockBulkAvecPrix($mouvements);
            $this->info("✅ Stock mis à jour.");
        }

        // Résumé
        $this->line('');
        $this->info("─────────────────────────────────────");
        $this->info("📊 Résumé :");
        $this->line("   Fichiers traités  : " . count($processed));
        $this->line("   Fichiers ignorés  : " . count($ignored));
        $this->line("   Lignes insérées   : {$totalNew}");
        $this->line("   Doublons ignorés  : {$totalSkip}");
        if (!empty($errors)) {
            $this->error("   Erreurs           : " . count($errors));
            foreach ($errors as $err) {
                $this->error("   → {$err}");
            }
        }
        $this->info("─────────────────────────────────────");

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
