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
 * Utilise FromQuery + chunk pour éviter les erreurs mémoire sur 27 000+ articles.
 */
class StockExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    ShouldAutoSize
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

    // FromQuery — Maatwebsite gère le chunking automatiquement
    public function query()
    {
        return Stocks::when(
            $this->search,
            fn($q) =>
            $q->where('Code',         'like', "%{$this->search}%")
                ->orWhere('Liblong',    'like', "%{$this->search}%")
                ->orWhere('fournisseur', 'like', "%{$this->search}%")
        )
            ->when($this->fournisseur, fn($q) => $q->where('fournisseur', $this->fournisseur))
            ->when($this->maxQte !== '', fn($q) => $q->where('QuantiteStock', '<', (float) $this->maxQte))
            ->orderBy('Code')
            ->select('Code', 'Liblong', 'fournisseur', 'QuantiteStock', 'PrixU', 'PrixTotal');
    }

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

    public function chunkSize(): int
    {
        return 500;
    }
}
