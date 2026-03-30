<?php

namespace App\Exports;

use App\Models\Stocks;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * StockExport
 * ─────────────────────────────────────────────────────────────────────────────
 * Anti-timeout sur 30 000+ articles :
 *
 *   • FromQuery    → Maatwebsite construit le fichier en streaming sans tout
 *                    charger en mémoire.
 *   • WithChunkReading (chunkSize 500) → lecture par paquets de 500 lignes.
 *   • ShouldAutoSize   → largeur colonnes automatique (coût négligeable).
 *   • Pas de WithCustomQuerySize séparé — chunkSize() suffit avec FromQuery.
 *
 * Pour les exports très volumineux (> 50 000 lignes), préférer le driver
 * "Csv" ou une file d'attente (QueuedExport / StoreExport + notification).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class StockExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    ShouldAutoSize,
    WithChunkReading
{
    private string $search;
    private string $fournisseur;
    private string $maxQte;

    public function __construct(
        string $search      = '',
        string $fournisseur = '',
        string $maxQte      = ''
    ) {
        $this->search      = $search;
        $this->fournisseur = $fournisseur;
        $this->maxQte      = $maxQte;
    }

    // ── Requête — Maatwebsite gère les chunks automatiquement ─────────────────
    public function query()
    {
        return Stocks::when(
            $this->search,
            fn($q) =>
            $q->where('Code',          'like', "%{$this->search}%")
                ->orWhere('Liblong',      'like', "%{$this->search}%")
                ->orWhere('fournisseur',  'like', "%{$this->search}%")
        )
            ->when($this->fournisseur, fn($q) => $q->where('fournisseur', $this->fournisseur))
            ->when(
                $this->maxQte !== '',
                fn($q) => $q->where('QuantiteStock', '<', (float) $this->maxQte)
            )
            ->orderBy('Code')
            ->select('Code', 'Liblong', 'fournisseur', 'QuantiteStock', 'PrixU', 'PrixTotal');
    }

    // ── Taille des chunks de lecture (500 lignes × N colonnes légères) ────────
    public function chunkSize(): int
    {
        return 500;
    }

    // ── En-têtes ──────────────────────────────────────────────────────────────
    public function headings(): array
    {
        return [
            'Code',
            'Désignation',
            'Fournisseur',
            'Quantité Stock',
            'Prix Unitaire (Ar)',
            'Prix Total (Ar)',
        ];
    }

    // ── Mapping ligne par ligne ───────────────────────────────────────────────
    public function map($stock): array
    {
        return [
            $stock->Code,
            $stock->Liblong,
            $stock->fournisseur ?? '',
            $stock->QuantiteStock,
            $stock->PrixU,
            $stock->PrixTotal,
        ];
    }

    // ── Style ligne d'en-tête (rouge bordeaux #7a1a2e, texte blanc) ──────────
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF7a1a2e']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
