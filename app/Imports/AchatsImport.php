<?php

namespace App\Imports;

use App\Models\ImportedFile;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AchatsImport
 * Modification unique vs version originale :
 *   → $this->insertedRows[] mémorise chaque ligne insérée
 *   → getInsertedRows() expose ces lignes à AchatController
 *   → AchatController appelle VenteStockService::ajusterStockBulkAvecPrix()
 * Toute la logique de déduplication SHA-256 
 */
class AchatsImport implements ToCollection, WithChunkReading
{
    protected string $filename;
    protected string $path;
    protected string $importDate;

    protected int $newRows     = 0;
    protected int $skippedRows = 0;

    protected array $insertedRows    = [];   // ← NOUVEAU
    protected array $knownHashes     = [];
    protected array $occurrenceCount = [];
    protected bool  $initialized     = false;
    protected ?int  $importedFileId  = null;

    public function __construct(string $filename, string $path = '', ?string $importDate = null)
    {
        $this->filename   = $filename;
        $this->path       = $path;
        $this->importDate = $importDate ?? now()->format('Y-m-d');
    }

    public function collection(Collection $rows): void
    {
        $importedFile = ImportedFile::firstOrCreate(
            ['filename' => $this->filename],
            ['path' => $this->path, 'imported_at' => now(), 'total_rows' => 0]
        );
        $importedFile->update(['path' => $this->path, 'imported_at' => now()]);
        $this->importedFileId = $importedFile->id;

        if (!$this->initialized) {
            $this->initialized = true;
            $this->knownHashes = DB::table('imported_row_hashes')
                ->where('imported_file_id', $importedFile->id)
                ->pluck('row_hash')
                ->flip()
                ->all();
        }

        $newAchats = [];
        $newHashes = [];

        foreach ($rows as $row) {
            if (isset($row[0]) && strtolower(trim((string) $row[0])) === 'référence') continue;

            $code = trim((string) ($row[0] ?? ''));
            if ($code === '') continue;

            $liblong = trim((string) ($row[1] ?? ''));
            $prixU   = $this->parse($row[3] ?? '');
            $qte     = $this->parse($row[5] ?? '');

            $contentKey = $this->contentKey($code, $liblong, $prixU, $qte);
            $this->occurrenceCount[$contentKey] = ($this->occurrenceCount[$contentKey] ?? 0) + 1;
            $hash = $this->makeHash($contentKey, $this->occurrenceCount[$contentKey]);

            if (isset($this->knownHashes[$hash])) {
                $this->skippedRows++;
                continue;
            }

            $ligne = [
                'Code'          => $code,
                'Liblong'       => $liblong,
                'PrixU'         => $prixU,
                'QuantiteAchat' => $qte,
                'date'          => $this->importDate,
            ];

            $newAchats[]          = $ligne;
            $this->insertedRows[] = $ligne;   // ← NOUVEAU
            $newHashes[]          = ['imported_file_id' => $this->importedFileId, 'row_hash' => $hash];

            $this->knownHashes[$hash] = true;
            $this->newRows++;
        }

        if (!empty($newAchats)) {
            DB::table('achats')->insert($newAchats);
            DB::table('imported_row_hashes')->insertOrIgnore($newHashes);
            $importedFile->increment('total_rows', count($newAchats));
        }
    }

    private function contentKey(string $code, string $liblong, float $prixU, float $qte): string
    {
        return implode('|', [
            $code,
            $liblong,
            number_format($prixU, 4, '.', ''),
            number_format($qte,   4, '.', ''),
        ]);
    }

    private function makeHash(string $contentKey, int $occurrence): string
    {
        return hash('sha256', $contentKey . '|occ=' . $occurrence);
    }

    protected function parse($value): float
    {
        if ($value === null || $value === '') return 0.0;
        $v = str_replace([' ', "\u{00A0}"], '', (string) $value);
        return (float) str_replace(',', '.', $v);
    }

    public function getNewRowsCount(): int
    {
        return $this->newRows;
    }
    public function getSkippedRowsCount(): int
    {
        return $this->skippedRows;
    }
    public function getInsertedRows(): array
    {
        return $this->insertedRows;
    }  // ← NOUVEAU
    public function chunkSize(): int
    {
        return 500;
    }
}
