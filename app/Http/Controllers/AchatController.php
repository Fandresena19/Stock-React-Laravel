<?php

namespace App\Http\Controllers;

use App\Imports\AchatsImport;
use App\Models\Achat;
use App\Models\ImportedFile;
use App\Services\VenteStockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AchatController extends Controller
{
    private string $watchFolder;

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
        $this->watchFolder = rtrim(
            env('ACHATS_WATCH_FOLDER', storage_path('app/achats_auto')),
            '/\\'
        );
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $date   = $request->input('date', '');

        $achats = Achat::when(
            $search,
            fn($q) =>
            $q->where('Code',    'like', "%{$search}%")
                ->orWhere('Liblong', 'like', "%{$search}%")
        )
            ->when($date, fn($q) => $q->whereDate('date', $date))
            ->orderBy('date', 'desc')
            ->orderBy('Code')
            ->paginate(20)
            ->through(fn($a) => [
                'id'            => null, // pas d'id en base
                'Code'          => $a->Code,
                'Liblong'       => $a->Liblong,
                'PrixU'         => $a->PrixU,
                'QuantiteAchat' => $a->QuantiteAchat,
                'montant'       => round($a->PrixU * $a->QuantiteAchat, 2),
                'date'          => $a->date?->format('d/m/Y'),
                'dateRaw'       => $a->date?->format('Y-m-d'), // pour la suppression
            ])
            ->withQueryString();

        $importHistory = ImportedFile::orderByDesc('imported_at')
            ->limit(10)
            ->get(['filename', 'total_rows', 'imported_at']);

        $stats = [
            'total_lignes'  => Achat::count(),
            'total_montant' => Achat::selectRaw('ROUND(SUM(PrixU * QuantiteAchat), 2) as total')->value('total') ?? 0,
            'derniere_date' => Achat::max('date'),
            'fichiers'      => ImportedFile::count(),
            'qte_hier' => Achat::where('date', Carbon::yesterday()->format('Y-m-d'))
                ->sum('QuantiteAchat'),
        ];

        return Inertia::render('achats/index', [
            'achats'        => $achats,
            'importHistory' => $importHistory,
            'stats'         => $stats,
            'filters'       => ['search' => $search, 'date' => $date],
            'watchFolder'   => $this->buildMonthFolder(Carbon::yesterday()),
        ]);
    }

    // =========================================================================
    // IMPORT MANUEL
    // La date peut être saisie manuellement (si import en retard)
    // Par défaut = hier
    // =========================================================================

    public function import(Request $request)
    {
        $request->validate([
            'files'   => 'required',
            'files.*' => 'mimes:xlsx,xls,csv|max:20480',
            'date'    => 'nullable|date',
        ]);

        $importDate = $request->filled('date')
            ? Carbon::parse($request->input('date'))->format('Y-m-d')
            : Carbon::yesterday()->format('Y-m-d');

        $totalNew     = 0;
        $totalSkipped = 0;
        $mouvements   = [];

        foreach ($request->file('files') as $file) {
            $importer = new AchatsImport(
                $file->getClientOriginalName(),
                $file->getPathname(),
                $importDate
            );
            Excel::import($importer, $file);

            $totalNew     += $importer->getNewRowsCount();
            $totalSkipped += $importer->getSkippedRowsCount();

            foreach ($importer->getInsertedRows() as $row) {
                $mouvements[] = [
                    'code'     => $row['Code'],
                    'quantite' => (float) $row['QuantiteAchat'],
                    'prixU'    => (float) $row['PrixU'],
                    'date'     => $importDate,
                ];
            }
        }

        // Mettre à jour le stock immédiatement (sans attendre le job)
        if (!empty($mouvements)) {
            $this->stockService->ajusterStockBulkAvecPrix($mouvements);
        }

        $msg = count($request->file('files')) . ' fichier(s) traité(s) — ' . "{$totalNew} ligne(s) insérée(s)";
        if ($totalSkipped > 0) $msg .= ", {$totalSkipped} ignorée(s) (doublons).";

        return redirect()->route('achats.index')->with('success', $msg);
    }

    // =========================================================================
    // IMPORT AUTOMATIQUE — fichiers modifiés HIER dans le dossier surveillé
    // Commande : php artisan import:achat-auto
    // =========================================================================

    public function importAuto(Request $request)
    {
        $yesterday   = Carbon::yesterday();
        $monthFolder = $this->buildMonthFolder($yesterday);

        if (!File::exists($monthFolder)) {
            return back()->with('error', "Dossier introuvable : {$monthFolder}. Vérifiez ACHATS_WATCH_FOLDER dans .env");
        }

        $archiveFolder = $monthFolder . DIRECTORY_SEPARATOR . 'ARCHIVE';
        if (!File::exists($archiveFolder)) {
            File::makeDirectory($archiveFolder, 0755, true);
        }

        $allFiles = collect(File::files($monthFolder))
            ->filter(fn($f) => in_array(strtolower($f->getExtension()), ['xlsx', 'xls', 'csv']));

        if ($allFiles->isEmpty()) {
            return back()->with('info', "Aucun fichier Excel/CSV dans : {$monthFolder}");
        }

        $totalNew   = 0;
        $totalSkip  = 0;
        $processed  = [];
        $ignored    = [];
        $errors     = [];
        $mouvements = [];

        foreach ($allFiles as $file) {
            $filename = $file->getFilename();
            $realPath = $file->getRealPath();
            $mtime    = Carbon::createFromTimestamp($file->getMTime());

            if (!$mtime->isYesterday()) {
                $ignored[] = $filename;
                continue;
            }

            $importDate = $mtime->format('Y-m-d');

            try {
                $importer = new AchatsImport($filename, $realPath, $importDate);
                Excel::import($importer, $realPath);

                $totalNew    += $importer->getNewRowsCount();
                $totalSkip   += $importer->getSkippedRowsCount();
                $processed[]  = $filename;

                foreach ($importer->getInsertedRows() as $row) {
                    $mouvements[] = [
                        'code'     => $row['Code'],
                        'quantite' => (float) $row['QuantiteAchat'],
                        'prixU'    => (float) $row['PrixU'],
                        'date'     => $importDate,
                    ];
                }

                File::copy(
                    $realPath,
                    $archiveFolder . DIRECTORY_SEPARATOR . $mtime->format('Ymd') . '_' . $filename
                );
            } catch (\Throwable $e) {
                $errors[] = $filename . ' : ' . $e->getMessage();
                Log::error('AchatController@importAuto', ['file' => $filename, 'message' => $e->getMessage()]);
            }
        }

        if (!empty($mouvements)) {
            $this->stockService->ajusterStockBulkAvecPrix($mouvements);
        }

        if (empty($processed) && empty($errors)) {
            $msg = 'Aucun fichier modifié hier.';
            if (count($ignored)) $msg .= ' ' . count($ignored) . ' fichier(s) ignoré(s).';
            return back()->with('info', $msg);
        }

        $msg = count($processed) . ' fichier(s) traité(s) — ' . "{$totalNew} ligne(s) insérée(s)";
        if ($totalSkip > 0)      $msg .= ", {$totalSkip} ignorée(s) (doublons)";
        if (count($ignored) > 0) $msg .= '. ' . count($ignored) . ' ignoré(s) (date ≠ hier)';
        if (!empty($errors))     $msg .= '. ⚠ ' . count($errors) . ' erreur(s) — voir les logs.';

        return back()->with(!empty($errors) ? 'error' : 'success', $msg);
    }

    // =========================================================================
    // DELETE — diminue le stock AVANT de supprimer
    // La table achats n'a pas de clé primaire — on utilise DB::table()
    // et on identifie la ligne par Code + date + PrixU + QuantiteAchat
    // =========================================================================

    public function destroy(Request $request)
    {
        $request->validate([
            'Code'          => 'required|string',
            'date'          => 'required|string',
            'PrixU'         => 'required|numeric',
            'QuantiteAchat' => 'required|numeric',
        ]);

        $code          = $request->Code;
        $date          = $request->date;
        $prixU         = (float) $request->PrixU;
        $quantiteAchat = (float) $request->QuantiteAchat;

        // Vérifier que la ligne existe
        $exists = \Illuminate\Support\Facades\DB::table('achats')
            ->where('Code',          $code)
            ->whereDate('date',      $date)
            ->where('PrixU',         $prixU)
            ->where('QuantiteAchat', $quantiteAchat)
            ->exists();

        if (!$exists) {
            return back()->with('error', 'Ligne introuvable.');
        }

        // Annuler l'effet de cet achat sur le stock
        $this->stockService->ajusterStockVente(
            code: $code,
            quantite: $quantiteAchat,
        );

        // Supprimer 1 seule ligne (LIMIT 1)
        \Illuminate\Support\Facades\DB::statement('
            DELETE FROM achats
            WHERE Code = ? AND date = ? AND PrixU = ? AND QuantiteAchat = ?
            LIMIT 1
        ', [$code, $date, $prixU, $quantiteAchat]);

        cache()->forget('sync_done');

        return back()->with('success', 'Ligne supprimée.');
    }

    // =========================================================================
    // PRIVÉ
    // =========================================================================

    private function buildMonthFolder(Carbon $date): string
    {
        return $this->watchFolder
            . DIRECTORY_SEPARATOR . 'ACHAT ' . $date->year
            . DIRECTORY_SEPARATOR . self::MOIS[$date->month] . ' ' . $date->year;
    }
}
