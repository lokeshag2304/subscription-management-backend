<?php

namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ModuleSheet implements FromCollection, WithTitle, WithHeadings, ShouldAutoSize, WithStyles
{
    protected $title;
    protected $data;

    public function __construct(string $title, array $data)
    {
        $this->title = $title;
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function headings(): array
    {
        if (!empty($this->data)) {
            $firstRow = reset($this->data);
            return array_map(function($key) {
                return ucwords(str_replace('_', ' ', $key));
            }, array_keys((array)$firstRow));
        }

        // Default headings if data is empty
        return match($this->title) {
            'Subscriptions' => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status'],
            'SSL'           => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status'],
            'Hosting'       => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status'],
            'Domains'       => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status'],
            'Emails'        => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status', 'Email'],
            'Counter'       => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status'],
            default         => ['ID', 'Domain', 'Product', 'Client', 'Vendor', 'Amount', 'Renewal Date', 'Status']
        };
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FF7A1A'] // FlyingStars Orange
                ]
            ],
        ];
    }
}
