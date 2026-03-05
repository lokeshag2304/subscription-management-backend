<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * DuplicateExporter
 *
 * Generates an Excel file that contains only the duplicate rows detected during
 * an import run. The headings must be passed in so this exporter is completely
 * module-agnostic.
 *
 * Usage:
 *   new DuplicateExporter($headings, $rows)
 */
class DuplicateExporter implements FromArray, WithHeadings, WithStyles
{
    private array $headings;
    private array $rows;

    public function __construct(array $headings, array $rows)
    {
        $this->headings = $headings;
        $this->rows     = $rows;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Bold header row
            1 => ['font' => ['bold' => true]],
        ];
    }
}
