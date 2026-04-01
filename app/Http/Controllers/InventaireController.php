<?php

namespace App\Http\Controllers;

use App\Imports\InventaireImport;
use App\Models\Inventaire;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class InventaireController extends Controller
{
    public function index()
    {
        $historique = Inventaire::orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($i) => [
                'id'                  => $i->id,
                'date_inventaire'     => $i->date_inventaire?->format('d/m/Y'),
                'type'                => $i->type,
                'filename'            => $i->filename,
                'nb_lignes_modifiees' => $i->nb_lignes_modifiees,
                'nb_lignes_ignorees'  => $i->nb_lignes_ignorees,
                'notes'               => $i->notes,
                'created_at'          => $i->created_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('inventaire/index', [
            'historique' => $historique,
        ]);
    }

    // =========================================================================
    // IMPORT
    // =========================================================================

    public function import(Request $request)
    {
        set_time_limit(300); // 5 minutes max pour les gros fichiers

        $request->validate([
            'file'  => 'required|mimes:xlsx,xls,csv|max:51200',
            'date'  => 'required|date',
            'type'  => 'required|in:total,partiel',
        ]);

        $date         = Carbon::parse($request->input('date'))->format('Y-m-d');
        $type         = $request->input('type');
        $uploadedFile = $request->file('file');
        $filename     = $uploadedFile->getClientOriginalName();
        $extension    = strtolower($uploadedFile->getClientOriginalExtension());

        $tmpPath   = $uploadedFile->getRealPath();
        $fixedPath = $tmpPath . '.' . $extension;
        copy($tmpPath, $fixedPath);

        // Détecter le vrai format via magic bytes
        $realFormat = $this->detectRealFormat($fixedPath, $extension);

        try {
            // HTML déguisé en .xls → parser natif PHP (10x plus rapide)
            if ($realFormat === 'html') {
                [$rows, $skipped] = $this->parseHtmlExcel($fixedPath);
            } else {
                $importer   = new InventaireImport();
                $readerType = match ($realFormat) {
                    'xls'  => \Maatwebsite\Excel\Excel::XLS,
                    'xml'  => \Maatwebsite\Excel\Excel::XML,
                    'csv'  => \Maatwebsite\Excel\Excel::CSV,
                    default => \Maatwebsite\Excel\Excel::XLSX,
                };
                Excel::import($importer, $fixedPath, null, $readerType);
                $rows    = $importer->getRows();
                $skipped = $importer->getSkipped();
            }
        } catch (\Throwable $e) {
            @unlink($fixedPath);
            return response()->json([
                'success' => false,
                'message' => 'Impossible de lire le fichier : ' . $e->getMessage(),
            ], 422);
        }

        @unlink($fixedPath);

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune ligne valide trouvée. Vérifiez que les colonnes "Code" et "quantite" sont présentes.',
            ], 422);
        }

        $modified = 0;

        DB::beginTransaction();
        try {
            $modified = $type === 'total'
                ? $this->importerTotal($rows)
                : $this->importerPartiel($rows);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur mise à jour stocks : ' . $e->getMessage(),
            ], 500);
        }

        Inventaire::create([
            'date_inventaire'     => $date,
            'type'                => $type,
            'filename'            => $filename,
            'nb_lignes_modifiees' => $modified,
            'nb_lignes_ignorees'  => $skipped,
            'notes'               => null,
        ]);

        cache()->forget('sync_done');

        $label = $type === 'total' ? 'Inventaire total' : 'Inventaire partiel';
        $msg   = "{$label} du {$date} importé — {$modified} article(s) mis à jour.";
        if ($skipped > 0) $msg .= " {$skipped} ligne(s) ignorée(s).";

        return response()->json(['success' => true, 'message' => $msg]);
    }

    // =========================================================================
    // Détection du vrai format via magic bytes
    // =========================================================================

    private function detectRealFormat(string $path, string $declaredExtension): string
    {
        $handle = fopen($path, 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        // Vrai XLS binaire OLE
        if (str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'xls';
        }

        // Vrai XLSX (ZIP)
        if (str_starts_with($header, "PK\x03\x04")) {
            return 'xlsx';
        }

        // Lire le début pour détecter XML / HTML
        $peek = strtolower(ltrim(file_get_contents($path, false, null, 0, 2048)));

        if (str_contains($peek, '<html') || str_contains($peek, '<!doctype html')) {
            return 'html'; // HTML déguisé en .xls → parser natif
        }

        if (str_starts_with($peek, '<?xml') || str_contains($peek, '<workbook')) {
            return 'xml';
        }

        return $declaredExtension === 'csv' ? 'csv' : 'xlsx';
    }

    // =========================================================================
    // Parser HTML natif — remplace le reader HTML de PhpSpreadsheet
    // Lit le fichier HTML/XLS directement avec DOMDocument (très rapide)
    // =========================================================================

    private function parseHtmlExcel(string $path): array
    {
        $rows    = collect();
        $skipped = 0;

        // Charger le HTML en supprimant les warnings d'encodage
        $content = file_get_contents($path);

        // Détecter et forcer l'encodage UTF-8 si nécessaire
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();

        $tables = $dom->getElementsByTagName('table');
        if ($tables->length === 0) {
            return [$rows, $skipped];
        }

        // Prendre la première table
        $table = $tables->item(0);
        $allRows = $table->getElementsByTagName('tr');

        if ($allRows->length === 0) {
            return [$rows, $skipped];
        }

        // ── Lire la ligne d'en-tête ──────────────────────────────────────────
        $headerRow  = $allRows->item(0);
        $headerCells = $headerRow->getElementsByTagName('td');
        if ($headerCells->length === 0) {
            $headerCells = $headerRow->getElementsByTagName('th');
        }

        $headers = [];
        foreach ($headerCells as $cell) {
            $headers[] = strtolower(trim(str_replace(
                [' ', '_', '-', "\xc2\xa0"], // \xc2\xa0 = &nbsp; en UTF-8
                '',
                $cell->textContent
            )));
        }

        // Mapper les colonnes connues
        $colCode  = $this->findColIndex($headers, ['code']);
        $colQte   = $this->findColIndex($headers, ['quantitestock', 'quantite', 'qte', 'stock', 'qty', 'quantity']);
        $colPrix  = $this->findColIndex($headers, ['prixu', 'prix_u', 'pu', 'prix', 'prixunitaire', 'price']);

        if ($colCode === null || $colQte === null) {
            // En-tête introuvable → essayer la 2ème ligne comme en-tête
            return [$rows, $skipped];
        }

        // ── Lire les lignes de données ────────────────────────────────────────
        for ($i = 1; $i < $allRows->length; $i++) {
            $tr    = $allRows->item($i);
            $cells = $tr->getElementsByTagName('td');

            if ($cells->length === 0) {
                $skipped++;
                continue;
            }

            $code = isset($headers[$colCode])
                ? trim($cells->item($colCode)?->textContent ?? '')
                : '';

            if ($code === '') {
                $skipped++;
                continue;
            }

            $qteRaw = trim($cells->item($colQte)?->textContent ?? '');
            if ($qteRaw === '') {
                $skipped++;
                continue;
            }

            // Nettoyer les nombres : "1 234,56" → 1234.56
            $qte  = (float) str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $qteRaw);

            $prix = null;
            if ($colPrix !== null && $cells->length > $colPrix) {
                $prixRaw = trim($cells->item($colPrix)?->textContent ?? '');
                if ($prixRaw !== '') {
                    $prix = (float) str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $prixRaw);
                }
            }

            $rows->push([
                'Code'          => $code,
                'QuantiteStock' => $qte,
                'PrixU'         => $prix,
            ]);
        }

        return [$rows, $skipped];
    }

    private function findColIndex(array $headers, array $keys): ?int
    {
        foreach ($headers as $i => $h) {
            foreach ($keys as $key) {
                if ($h === strtolower(str_replace([' ', '_', '-'], '', $key))) {
                    return $i;
                }
            }
        }
        return null;
    }

    // =========================================================================
    // Inventaire TOTAL
    // =========================================================================

    private function importerTotal(Collection $rows): int
    {
        DB::table('stocks')->update(['QuantiteStock' => 0, 'PrixTotal' => 0]);

        $modified = 0;

        foreach ($rows->chunk(500) as $chunk) {
            $qteCase   = 'CASE `Code`';
            $prixCase  = 'CASE `Code`';
            $totalCase = 'CASE `Code`';
            $hasPrix   = false;
            $codes     = [];

            foreach ($chunk as $row) {
                $code = $row['Code'];
                $qte  = (float) $row['QuantiteStock'];
                $prix = $row['PrixU'];
                $q    = DB::getPdo()->quote($code);

                $qteCase .= " WHEN {$q} THEN {$qte}";

                if ($prix !== null) {
                    $hasPrix    = true;
                    $prixCase  .= " WHEN {$q} THEN {$prix}";
                    $totalCase .= " WHEN {$q} THEN {$qte} * {$prix}";
                } else {
                    $totalCase .= " WHEN {$q} THEN {$qte} * PrixU";
                }

                $codes[] = $q;
            }

            if (empty($codes)) continue;

            $inList    = implode(',', $codes);
            $modified += DB::table('stocks')->whereRaw("`Code` IN ({$inList})")->count();

            if ($hasPrix) {
                DB::statement("
                    UPDATE stocks
                    SET QuantiteStock = {$qteCase} ELSE QuantiteStock END,
                        PrixU         = {$prixCase} ELSE PrixU END,
                        PrixTotal     = {$totalCase} ELSE (QuantiteStock * PrixU) END
                    WHERE `Code` IN ({$inList})
                ");
            } else {
                DB::statement("
                    UPDATE stocks
                    SET QuantiteStock = {$qteCase} ELSE QuantiteStock END,
                        PrixTotal     = {$totalCase} ELSE (QuantiteStock * PrixU) END
                    WHERE `Code` IN ({$inList})
                ");
            }
        }

        return $modified;
    }

    // =========================================================================
    // Inventaire PARTIEL
    // =========================================================================

    private function importerPartiel(Collection $rows): int
    {
        $modified = 0;

        foreach ($rows->chunk(500) as $chunk) {
            $qteCase   = 'CASE `Code`';
            $prixCase  = 'CASE `Code`';
            $totalCase = 'CASE `Code`';
            $hasPrix   = false;
            $codes     = [];

            foreach ($chunk as $row) {
                $code = $row['Code'];
                $qte  = (float) $row['QuantiteStock'];
                $prix = $row['PrixU'];
                $q    = DB::getPdo()->quote($code);

                $qteCase .= " WHEN {$q} THEN {$qte}";

                if ($prix !== null) {
                    $hasPrix   = true;
                    $prixCase  .= " WHEN {$q} THEN {$prix}";
                    $totalCase .= " WHEN {$q} THEN {$qte} * {$prix}";
                } else {
                    $totalCase .= " WHEN {$q} THEN {$qte} * PrixU";
                }

                $codes[] = $q;
            }

            if (empty($codes)) continue;

            $inList    = implode(',', $codes);
            $modified += DB::table('stocks')->whereRaw("`Code` IN ({$inList})")->count();

            if ($hasPrix) {
                DB::statement("
                    UPDATE stocks
                    SET QuantiteStock = {$qteCase} ELSE QuantiteStock END,
                        PrixU         = {$prixCase} ELSE PrixU END,
                        PrixTotal     = {$totalCase} ELSE (QuantiteStock * PrixU) END
                    WHERE `Code` IN ({$inList})
                ");
            } else {
                DB::statement("
                    UPDATE stocks
                    SET QuantiteStock = {$qteCase} ELSE QuantiteStock END,
                        PrixTotal     = {$totalCase} ELSE (QuantiteStock * PrixU) END
                    WHERE `Code` IN ({$inList})
                ");
            }
        }

        return $modified;
    }

    // =========================================================================
    // SUPPRESSION
    // =========================================================================

    public function destroy(int $id)
    {
        Inventaire::findOrFail($id)->delete();
        return back()->with('success', 'Historique supprimé.');
    }
}
