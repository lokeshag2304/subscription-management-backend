<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use App\Exports\Sheets\SummarySheet;
use App\Exports\Sheets\ModuleSheet;

class GlobalDashboardExport implements WithMultipleSheets
{
    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function sheets(): array
    {
        $sheets = [];

        // Summary Sheet first
        $sheets[] = new SummarySheet($this->data['summary'] ?? []);

        // Module Sheets
        if (isset($this->data['subscriptions'])) $sheets[] = new ModuleSheet('Subscriptions', $this->data['subscriptions']);
        if (isset($this->data['ssl']))           $sheets[] = new ModuleSheet('SSL',           $this->data['ssl']);
        if (isset($this->data['hosting']))       $sheets[] = new ModuleSheet('Hosting',       $this->data['hosting']);
        if (isset($this->data['domains']))       $sheets[] = new ModuleSheet('Domains',       $this->data['domains']);
        if (isset($this->data['emails']))        $sheets[] = new ModuleSheet('Emails',        $this->data['emails']);
        if (isset($this->data['counter']))       $sheets[] = new ModuleSheet('Counter',       $this->data['counter']);

        return $sheets;
    }
}
