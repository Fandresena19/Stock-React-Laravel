<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;

/**
 * InventaireImport
 * ─────────────────────────────────────────────────────────────────────────────
 * Lit un fichier Excel/CSV d'inventaire.
 *
 * Colonnes reconnues (insensibles à la casse, espaces/tirets ignorés) :
 *   Code        → code
 *   Liblong     → ignoré
 *   QuantiteStock / quantite / qte / stock / qty / quantity
 *   PrixU / prix_u / prix / pu / prixunitaire / price  (optionnel)
 *
 * Correction OLE : le Reader est forcé via le vrai type MIME du fichier
 * pour éviter l'erreur "not recognised as an OLE file".
 * ─────────────────────────────────────────────────────────────────────────────
 */
class InventaireImport implements ToCollection, WithChunkReading, WithHeadingRow, SkipsEmptyRows
{
    private Collection $rows;
    private int        $skipped = 0;

    public function __construct()
    {
        $this->rows = collect();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Traitement des lignes
    // ──────────────────────────────────────────────────────────────────────────

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            // ── Code (obligatoire) ────────────────────────────────────────────
            $code = $this->findValue($row, ['code']);
            if ($code === null || trim((string) $code) === '') {
                $this->skipped++;
                continue;
            }

            // ── Quantité (obligatoire) ────────────────────────────────────────
            // Reconnaît : quantitestock, quantite, qte, stock, qty, quantity
            $quantite = $this->findValue($row, [
                'quantitestock',
                'quantite',
                'qte',
                'stock',
                'qty',
                'quantity',
            ]);

            if ($quantite === null || trim((string) $quantite) === '') {
                $this->skipped++;
                continue;
            }

            // ── Prix unitaire (facultatif) ────────────────────────────────────
            $prixU = $this->findValue($row, [
                'prixu',
                'prix_u',
                'pu',
                'prix',
                'prixunitaire',
                'price',
            ]);

            $this->rows->push([
                'Code'          => trim((string) $code),
                'QuantiteStock' => (float) $quantite,
                'PrixU'         => ($prixU !== null && trim((string) $prixU) !== '')
                    ? (float) $prixU
                    : null,
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Recherche d'une valeur par nom de colonne (insensible à la casse)
    // ──────────────────────────────────────────────────────────────────────────

    private function findValue(Collection $row, array $keys): mixed
    {
        foreach ($row->toArray() as $colName => $value) {
            $normalized = strtolower(trim(str_replace([' ', '_', '-'], '', (string) $colName)));
            foreach ($keys as $key) {
                $normalizedKey = strtolower(str_replace([' ', '_', '-'], '', $key));
                if ($normalized === $normalizedKey) {
                    return $value;
                }
            }
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Getters
    // ──────────────────────────────────────────────────────────────────────────

    public function getRows(): Collection
    {
        return $this->rows;
    }
    public function getSkipped(): int
    {
        return $this->skipped;
    }
    public function chunkSize(): int
    {
        return 500;
    }
}
