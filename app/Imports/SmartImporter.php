<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * SmartImporter — Shared base for all module importers.
 *
 * Features:
 *  - Lookup caches loaded once in memory (products, clients, vendors, domains)
 *  - Correct duplicate key: product_id + vendor_id + renewal_date + client_id
 *  - Batch-load existing records before processing (no per-row DB query)
 *  - In-file duplicate detection (within same uploaded file)
 *  - DB::transaction() per insert to prevent race conditions
 *  - Duplicate rows collected and exported to Excel after processing
 *  - Counters: inserted / duplicates / failed
 *
 * Subclasses implement:
 *  - tableName()         — target DB table
 *  - clientTable()       — 'superadmins' | 'users'
 *  - columnHeaders()     — array of Excel column headers matching the import format
 *  - duplicateKey()      — build dedup fingerprint from parsed row data
 *  - existingDupKeys()   — batch-load existing records as fingerprint hash set
 *  - buildInsertRow()    — assemble array for DB insert
 *  - parseRow()          — extract typed values from raw spreadsheet row
 *  - rawRowToArray()     — convert raw row back to array format for dup-export
 */
abstract class SmartImporter implements ToCollection, WithChunkReading
{
    public int   $inserted         = 0;
    public int   $duplicates       = 0;
    public int   $failed           = 0;
    public int   $totalRows        = 0;
    public array $errors           = [];
    public ?string $duplicateFile  = null;   // set after export

    protected ?int   $forcedClientId;
    protected string $moduleName   = 'import'; // set by ImportService / controllers

    protected array $productCache = [];
    protected array $clientCache  = [];
    protected array $vendorCache  = [];
    protected array $domainCache  = [];

    protected array $productNamesMap = [];
    protected array $clientNamesMap  = [];
    protected array $vendorNamesMap  = [];
    protected array $domainNamesMap  = [];

    /** Raw duplicate rows collected during processing, for Excel export */
    private array $duplicateRows  = [];

    public function __construct(?int $forcedClientId = null)
    {
        $this->forcedClientId = $forcedClientId;
    }

    // ── Abstract interface ─────────────────────────────────────────────────────

    /** DB table to insert into */
    abstract protected function tableName(): string;

    /** Table that holds client records — 'superadmins' | 'users' */
    abstract protected function clientTable(): string;

    /**
     * Column headers for the duplicate export Excel file.
     * Must match the order of rawRowToArray().
     * @return string[]
     */
    abstract protected function columnHeaders(): array;

    /**
     * Build the dedup fingerprint from resolved parsed data.
     * @param array $parsed  Output of parseRow()
     * @return string
     */
    abstract protected function duplicateKey(array $parsed): string;

    /**
     * Load all existing rows matching chunk and return as fingerprint => true map.
     * Must use the same key format as duplicateKey().
     * @param array $parsedRows
     * @return array<string, true>
     */
    abstract protected function existingDupKeys(array $parsedRows): array;

    /**
     * Assemble the array to pass to DB::table()->insert().
     * @param array  $parsed
     * @param string $nowTimestamp
     * @return array
     */
    abstract protected function buildInsertRow(array $parsed, string $nowTimestamp): array;

    /**
     * Extract typed, validated values from a raw Collection row.
     * Return null if the row should be counted as failed.
     *
     * @param \Illuminate\Support\Collection $row
     * @param int $rowNumber  1-based, for error messages
     * @return array|null
     */
    abstract protected function parseRow(Collection $row, int $rowNumber): ?array;

    /**
     * Convert a raw Collection row back to a plain array for Excel export.
     * The columns must match columnHeaders().
     *
     * @param \Illuminate\Support\Collection $row
     * @return array
     */
    abstract protected function rawRowToArray(Collection $row): array;

    // ── Public entry point ─────────────────────────────────────────────────────

    public function chunkSize(): int
    {
        return 1000;
    }

    public final function collection(Collection $rows)
    {
        $this->warmCaches();

        $nowTimestamp = now()->toDateTimeString();

        $parsedRowsData = [];
        $rawRowsData = [];
        $rowNumbers = [];

        foreach ($rows as $index => $row) {
            // Check for header row
            if ($this->totalRows === 0 && $index === 0) {
                $h = $this->columnHeaders()[0] ?? 'Product';
                if (strtolower($this->clean($row[0] ?? '')) === strtolower($this->clean($h))) {
                    continue;
                }
            }

            // Quick skip empty rows
            $isEmpty = true;
            foreach ($row as $val) {
                if ($val !== null && trim((string)$val) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if ($isEmpty) continue;

            $this->totalRows++;
            $rowNumber = $this->totalRows + 1;

            try {
                $parsed = $this->parseRow($row, $rowNumber);
            } catch (\Throwable $e) {
                $this->failed++;
                $this->errors[] = ['row' => $rowNumber, 'reason' => $e->getMessage()];
                continue;
            }

            if ($parsed === null) {
                $this->failed++;
                continue;
            }

            if ($parsed === []) {
                // missing critical fields, skip silently
                continue;
            }

            $parsedRowsData[] = $parsed;
            $rawRowsData[] = $row->all();
            $rowNumbers[] = $rowNumber;
        }

        if (empty($parsedRowsData)) {
            return;
        }

        // Fetch duplicate keys specifically for the current chunk
        $existingSet = $this->existingDupKeys($parsedRowsData);

        $inserts = [];

        foreach ($parsedRowsData as $i => $parsed) {
            $dupKey = $this->duplicateKey($parsed);
            $rawRow = $rawRowsData[$i];
            
            if (isset($existingSet[$dupKey])) {
                $this->duplicates++;
                $this->duplicateRows[] = $this->rawRowToArray(collect($rawRow));
                continue;
            }

            $existingSet[$dupKey] = true;
            $inserts[] = $this->buildInsertRow($parsed, $nowTimestamp);
        }

        if (!empty($inserts)) {
            try {
                // Batch insert
                DB::transaction(function () use ($inserts) {
                    DB::table($this->tableName())->insert($inserts);
                });
                $this->inserted += count($inserts);
            } catch (\Throwable $e) {
                // Fallback to row-by-row
                foreach ($inserts as $idx => $insertData) {
                    try {
                        DB::table($this->tableName())->insert($insertData);
                        $this->inserted++;
                    } catch (\Throwable $ex) {
                        if (isset($ex->errorInfo[1]) && $ex->errorInfo[1] === 1062) {
                            $this->duplicates++;
                            $this->duplicateRows[] = $this->rawRowToArray(collect($rawRowsData[$idx]));
                        } else {
                            $this->failed++;
                            $this->errors[] = ['row' => $rowNumbers[$idx], 'reason' => $ex->getMessage()];
                        }
                    }
                }
            }
        }
    }

    /**
     * Finalize the import: Export collected duplicates to a file.
     * This should be called AFTER Excel::import() completes.
     */
    public function finalizeImport(): ?string
    {
        if (empty($this->duplicateRows)) {
            return null;
        }

        $this->duplicateFile = $this->exportDuplicates();
        return $this->duplicateFile;
    }

    // ── Duplicate export ───────────────────────────────────────────────────────

    /**
     * Write collected duplicate rows to an Excel file.
     * Saved to storage/import_logs/  (disk: local, path relative to storage/app).
     * Returns the relative path of saved file, or null on failure.
     */
    private function exportDuplicates(): ?string
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Header row
            $headers = $this->columnHeaders();
            foreach ($headers as $colIndex => $heading) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, 1, $heading);
            }

            // Style header bold
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

            // Data rows
            foreach ($this->duplicateRows as $rowIdx => $rowData) {
                foreach ($rowData as $colIndex => $value) {
                    $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIdx + 2, $value);
                }
            }

            // Auto-width columns
            foreach (range(1, count($headers)) as $col) {
                $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            // File name: duplicates_<module>_<timestamp>.xlsx
            $module    = strtolower(str_replace(' ', '_', $this->moduleName));
            $timestamp = now()->format('Y_m_d_H_i');
            $fileName  = "duplicates_{$module}_{$timestamp}.xlsx";
            $dirPath   = storage_path('app/import_logs');

            if (!file_exists($dirPath)) {
                mkdir($dirPath, 0755, true);
            }

            $fullPath = "{$dirPath}/{$fileName}";

            $writer = new Xlsx($spreadsheet);
            $writer->save($fullPath);

            // Return relative path for DB storage and API response
            return "import_logs/{$fileName}";

        } catch (\Throwable $e) {
            // Non-fatal — import already done, just log the issue
            $this->errors[] = ['row' => 'export', 'reason' => 'Duplicate export failed: ' . $e->getMessage()];
            return null;
        }
    }

    // ── Shared helpers ─────────────────────────────────────────────────────────

    protected function warmCaches(): void
    {
        if (!empty($this->productCache)) return; // Only warm once per upload

        DB::table('products')->get(['id', 'name'])->each(function ($p) {
            try { $name = \App\Services\CryptService::decryptData($p->name); } catch (\Throwable $e) { $name = null; }
            $name = $name ?: $p->name;
            $key = $this->normalize($name);
            if (!isset($this->productCache[$key])) $this->productCache[$key] = (int)$p->id;
            $this->productCache[(string)$p->id] = (int)$p->id;
            $this->productNamesMap[(int)$p->id] = $key;
        });

        DB::table($this->clientTable())->get(['id', 'name'])->each(function ($c) {
            try { $name = \App\Services\CryptService::decryptData($c->name); } catch (\Throwable $e) { $name = null; }
            $name = $name ?: $c->name;
            $key = $this->normalize($name);
            if (!isset($this->clientCache[$key])) $this->clientCache[$key] = (int)$c->id;
            $this->clientCache[(string)$c->id] = (int)$c->id;
            $this->clientNamesMap[(int)$c->id] = $key;
        });

        DB::table('vendors')->get(['id', 'name'])->each(function ($v) {
            try { $name = \App\Services\CryptService::decryptData($v->name); } catch (\Throwable $e) { $name = null; }
            $name = $name ?: $v->name;
            $key = $this->normalize($name);
            if (!isset($this->vendorCache[$key])) $this->vendorCache[$key] = (int)$v->id;
            $this->vendorCache[(string)$v->id] = (int)$v->id;
            $this->vendorNamesMap[(int)$v->id] = $key;
        });

        // Domain cache — only needed by SSLImport, harmless for others
        DB::table('domains')->get(['id', 'name'])->each(function ($d) {
            try { $name = \App\Services\CryptService::decryptData($d->name); } catch (\Throwable $e) { $name = null; }
            $name = $name ?: $d->name;
            $key = $this->normalize($name);
            if (!isset($this->domainCache[$key])) $this->domainCache[$key] = (int)$d->id;
            $this->domainCache[(string)$d->id] = (int)$d->id;
            $this->domainNamesMap[(int)$d->id] = $key;
        });
    }

    /**
     * Resolve a name or numeric ID to a DB record ID.
     * Try: cache → partial DB LIKE → auto-create.
     */
    protected function smartResolve(string $table, string $name, array &$cache, bool $allowCreate = true): int
    {
        $normalized = $this->normalize($name);

        if (isset($cache[$normalized])) return $cache[$normalized];
        if (isset($cache[$name]))       return $cache[$name];

        if (!$allowCreate) return 0;

        $encryptedName = \App\Services\CryptService::encryptData($name);
        $data = ['name' => $encryptedName, 'created_at' => now()];

        if ($table === 'superadmins' || $table === 'users') {
            $data['email']         = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $name)) . '+' . uniqid() . '@import.local';
            $data['password']      = bcrypt(uniqid());
            $data['status']        = 1;
            $data['login_type']    = 3; // 3 = Client
            $data['two_step_auth'] = 1;
        }

        if ($table === 'domains' || $table === 'domain') {
            $data['client_id'] = $this->forcedClientId; // assign to this client
        }

        $id = DB::table($table)->insertGetId($data);
        $cache[$normalized] = $id;
        return $id;
    }

    /** Set the module name (used in export file name). Called by ImportService. */
    public function setModuleName(string $name): void
    {
        $this->moduleName = $name;
    }

    protected function normalize(?string $str): string
    {
        if ($str === null) return '';
        return strtolower(trim(preg_replace('/\s+/', ' ', $str)));
    }

    protected function clean($value): string
    {
        return trim((string)$value);
    }

    protected function parseDate($value): ?string
    {
        if (empty($value)) return null;

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $v = trim((string)$value);
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $v);
            if ($d && $d->format($fmt) === $v) return $d->format('Y-m-d');
        }

        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function calcDaysLeft(?string $date): ?int
    {
        if (!$date) return null;
        return (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false);
    }

    protected function normalizeAmount($raw): string
    {
        return number_format(round((float)str_replace(',', '', (string)$raw), 2), 2, '.', '');
    }
}
