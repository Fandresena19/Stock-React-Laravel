<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use App\Imports\AchatsImport;
use App\Models\ImportedFile;

class ImportAchatsAuto extends Command
{
    protected $signature   = 'import:achats-auto';
    protected $description = 'Import automatique des achats fournisseurs (fichiers modifiés hier)';

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

    public function handle(): int
    {
        $yesterday   = Carbon::yesterday();
        $year        = $yesterday->year;
        $monthName   = self::MOIS[$yesterday->month];
        $importDate  = $yesterday->format('Y-m-d');

        // ── Chemin depuis .env ────────────────────────────────────────────────
        // ACHATS_WATCH_FOLDER="D:\Stage\Achat"
        // → construit : D:\Stage\Achat\ACHAT 2026\MARS 2026
        $base     = rtrim(env('ACHATS_WATCH_FOLDER', storage_path('app/achats_auto')), '/\\');
        $basePath = $base . DIRECTORY_SEPARATOR . "ACHAT {$year}" . DIRECTORY_SEPARATOR . "{$monthName} {$year}";

        $this->info("📂 Dossier ciblé : $basePath");

        if (!File::exists($basePath)) {
            $this->error("❌ Dossier introuvable : $basePath");
            $this->line("   → Vérifiez ACHATS_WATCH_FOLDER dans .env");
            Log::error("ImportAchatsAuto — Dossier introuvable : $basePath");
            return self::FAILURE;
        }

        // Créer ARCHIVE si nécessaire
        $archivePath = $basePath . DIRECTORY_SEPARATOR . 'ARCHIVE';
        if (!File::exists($archivePath)) {
            File::makeDirectory($archivePath, 0755, true);
            $this->info("📁 Dossier ARCHIVE créé.");
        }

        $files = File::files($basePath);
        $this->info("📋 Fichiers trouvés : " . count($files));

        $totalImported  = 0;
        $totalSkipped   = 0;
        $filesProcessed = 0;

        foreach ($files as $file) {

            // Excel uniquement
            if (!in_array(strtolower($file->getExtension()), ['xlsx', 'xls', 'csv'])) {
                $this->line("  ⏭  Ignoré (non Excel/CSV) : " . $file->getFilename());
                continue;
            }

            $filename = $file->getFilename();
            $realPath = $file->getRealPath();

            // ── Filtre clé : date de modification = hier ──────────────────────
            $fileDate   = Carbon::createFromTimestamp($file->getMTime())->toDateString();
            $targetDate = $yesterday->toDateString();

            if ($fileDate !== $targetDate) {
                $this->line("  ⏭  Ignoré (modifié le $fileDate ≠ hier $targetDate) : $filename");
                continue;
            }

            // ── Statut du fichier (sans ImportedRow) ───────────────────────────
            $existingRecord = ImportedFile::where('filename', $filename)->first();
            if ($existingRecord) {
                $this->info("  🔄 Fichier connu ({$existingRecord->total_rows} ligne(s) déjà importées), scan des nouvelles lignes : $filename");
            } else {
                $this->info("  🆕 Nouveau fichier, import complet : $filename");
            }

            // ── Import ─────────────────────────────────────────────────────────
            try {
                $importer = new AchatsImport($filename, $realPath, $importDate);
                Excel::import($importer, $realPath);

                $newRows     = $importer->getNewRowsCount();
                $skippedRows = $importer->getSkippedRowsCount();

                $totalImported += $newRows;
                $totalSkipped  += $skippedRows;

                $this->info("  ✅ $filename → $newRows insérée(s), $skippedRows ignorée(s).");

                // Archiver avec préfixe date pour éviter les conflits
                $dest = $archivePath . DIRECTORY_SEPARATOR . $yesterday->format('Ymd') . '_' . $filename;
                File::copy($realPath, $dest);
                $this->info("  📁 Archivé : $dest");

                $filesProcessed++;
            } catch (\Throwable $e) {
                $this->error("  ❌ Erreur : $filename");
                $this->error("     " . $e->getMessage());
                Log::error("ImportAchatsAuto — Erreur import", [
                    'file'    => $filename,
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("──────────────────────────────────────────────────");
        $this->info("✔  Import terminé.");
        $this->info("   Fichiers traités  : $filesProcessed");
        $this->info("   Lignes insérées   : $totalImported");
        $this->info("   Lignes ignorées   : $totalSkipped");

        return self::SUCCESS;
    }
}
