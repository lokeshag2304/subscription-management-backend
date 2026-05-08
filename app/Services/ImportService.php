<?php

namespace App\Services;

use App\Models\ImportHistory;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ImportService
 *
 * Handles the full import pipeline:
 *  1. Store the uploaded file
 *  2. XLSX files are converted to CSV first to bypass ZipArchive open_basedir restrictions
 *  3. Run the importer (which also exports duplicate rows to xlsx)
 *  4. Persist logs to import_logs + import_histories (fault-tolerant — never crash the import)
 *  5. Return structured result to controllers
 *
 * Design principle: main import logic completes FIRST.
 * All logging is wrapped in silent try/catch so a DB or file error
 * in the logging layer NEVER propagates to the caller.
 */
class ImportService
{
    /**
     * @param  \Illuminate\Http\Request           $request
     * @param  \App\Imports\SmartImporter         $importer
     * @param  string                             $moduleName   e.g. 'Subscription'
     * @return array{
     *     history: ImportHistory|null,
     *     log: ImportLog|null,
     *     importer: \App\Imports\SmartImporter,
     *     duplicate_file: string|null,
     *     duplicate_file_url: string|null
     * }
     */
    public static function handleImport($request, $importer, string $moduleName): array
    {
        // ── 1. Store uploaded file into allowed directory before any parsing ──────
        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $filePath = null;
        $absolutePath = null;

        Log::info('[ImportService] File received: ' . $fileName . ' | size: ' . $file->getSize() . ' bytes | module: ' . $moduleName);

        // Ensure project-level storage/temp exists for open_basedir safety
        $projectTempDir = base_path('storage/temp');
        if (!is_dir($projectTempDir)) {
            @mkdir($projectTempDir, 0775, true);
        }

        try {
            // Primary: use Storage facade (Storage::store respects the configured local disk root)
            // This keeps the file inside storage/app which is always within open_basedir
            if (!\Illuminate\Support\Facades\Storage::exists('temp')) {
                \Illuminate\Support\Facades\Storage::makeDirectory('temp');
            }

            $filePath = $file->store('temp');          // → storage/app/temp/xxxxx.xlsx
            $absolutePath = storage_path('app/' . $filePath);
            Log::info('[ImportService] File stored via Storage::store() at: ' . $absolutePath . ' | exists: ' . (file_exists($absolutePath) ? 'YES' : 'NO'));

        } catch (\Throwable $e) {
            Log::error('[ImportService] Storage::store() failed: ' . $e->getMessage() . ' — trying native move()');

            // Fallback: native move_uploaded_file(), which bypasses open_basedir when moving out of upload_tmp_dir
            try {
                $path = storage_path('app/imports');
                if (!file_exists($path)) {
                    @mkdir($path, 0775, true);
                }
                $safeFilename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                $file->move($path, $safeFilename);
                $absolutePath = $path . '/' . $safeFilename;
                $filePath = 'imports/' . $safeFilename;
                Log::info('[ImportService] Fallback move() stored at: ' . $absolutePath . ' | exists: ' . (file_exists($absolutePath) ? 'YES' : 'NO'));
            } catch (\Throwable $ex) {
                Log::error('[ImportService] Both storage methods failed: ' . $ex->getMessage());
                throw $ex; // Re-throw so controller returns a proper 500
            }
        }

        // ── 1.5. Configure XLSX Library and Path overrides ────────────────────────
        // Force various temp dir settings to use the allowed project storage path.
        // This is critical for shared hosting with open_basedir restrictions.
        config(['excel.temporary_files.local_path' => $projectTempDir]);

        putenv('TMPDIR=' . $projectTempDir);
        putenv('TMP=' . $projectTempDir);
        putenv('TEMP=' . $projectTempDir);
        $_ENV['TMPDIR'] = $projectTempDir;
        $_ENV['TMP'] = $projectTempDir;
        $_ENV['TEMP'] = $projectTempDir;

        // ── 1.6. Convert XLSX → CSV inside allowed directory to avoid ZipArchive open_basedir errors ──
        // When PhpSpreadsheet reads an XLSX it extracts zip internals (/xl/worksheets/sheet1.xml etc.)
        // which land in restricted /tmp paths. Converting to CSV first avoids any zip extraction entirely.
        $processPath = $absolutePath; // default: use original path (handles CSV/XLS uploads natively)
        $csvTempPath = null;
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        Log::info('[ImportService] File extension detected: ' . $ext);

        if (in_array($ext, ['xlsx', 'xlsm', 'xltx', 'xltm', 'xls'])) {
            try {
                $csvTempPath = $projectTempDir . '/' . uniqid() . '.csv';
                Log::info('[ImportService] Converting to CSV. projectTempDir: ' . $projectTempDir . ' | Source: ' . $absolutePath . ' | Target: ' . $csvTempPath);

                $spreadsheet = IOFactory::load($absolutePath);

                $writer = IOFactory::createWriter($spreadsheet, 'Csv');
                $writer->save($csvTempPath);

                // Free memory
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                $processPath = $csvTempPath;
                $size = file_exists($csvTempPath) ? filesize($csvTempPath) : 0;
                Log::info("[ImportService] CSV conversion SUCCESS | exists: " . (file_exists($csvTempPath) ? 'YES' : 'NO') . " | size: $size bytes");
            } catch (\Throwable $e) {
                Log::error('[ImportService] CSV conversion FAILED: ' . $e->getMessage() . ' — using original file path');
                $processPath = $absolutePath;
                $csvTempPath = null;
            }
        }

        // ── 2. Tell importer its module name (used in duplicate xlsx filename) ─
        if (method_exists($importer, 'setModuleName')) {
            $importer->setModuleName($moduleName);
        }

        // ── 3. MAIN IMPORT — runs first, always completes ─────────────────────
        // Pass absolute path to explicitly bypass php://temp /tmp restriction rules during open_basedir
        Log::info('[ImportService] Starting Excel::import() with file: ' . $processPath . ' | Importer class: ' . get_class($importer));
        Excel::import($importer, $processPath);
        Log::info('[ImportService] Excel::import() DONE — inserted: ' . ($importer->inserted ?? 0) . ' | duplicates: ' . ($importer->duplicates ?? 0) . ' | failed: ' . ($importer->failed ?? 0) . ' | errors: ' . json_encode($importer->errors ?? []));

        // ── 3.5. FINALIZE (Export duplicates if any) ─────────────────────────

        $duplicateFilePath = null;
        if (method_exists($importer, 'finalizeImport')) {
            $duplicateFilePath = $importer->finalizeImport();
        }

        // All counters are now settled on $importer
        $inserted = $importer->inserted ?? 0;
        $duplicates = $importer->duplicates ?? 0;
        $failed = $importer->failed ?? 0;
        $totalRows = $importer->totalRows ?? ($inserted + $duplicates + $failed);

        $duplicateFileUrl = $duplicateFilePath
            ? url('/api/import-logs/download/' . ltrim($duplicateFilePath, '/'))
            : null;

        // ── 4. Resolve importing user (best-effort) ────────────────────────────
        $importedBy = 'System / Admin';
        $clientId = null;

        try {
            $authUserId = $request->input('auth_user_id') ?? $request->attributes->get('auth_user_id') ?? $request->input('admin_id') ?? $request->input('client_id');

            if ($authUserId) {
                // Fetch user from DB since auth()->check() might be false for JWT stateless
                $user = \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $authUserId)->first();
                if ($user) {
                    $importedBy = $user->name ?? $user->email ?? ('User ID: ' . $user->id);
                    try {
                        $decrypted = \App\Services\CryptService::decryptData($importedBy);
                        if ($decrypted)
                            $importedBy = $decrypted;
                    } catch (\Throwable $e) {
                    }

                    // Identify if client
                    if ($user->login_type == 3) {
                        $clientId = $user->id;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        // ── 5. Write to import_logs (FAULT-TOLERANT) ──────────────────────────
        $log = null;
        try {
            // Build the insert data using only columns that exist in the table
            // This makes the insert safe even if future migrations haven't run yet
            $logData = self::filterToExistingColumns('import_logs', [
                'module' => strtolower($moduleName),
                'file_name' => $fileName,
                'total_rows' => $totalRows,
                'inserted' => $inserted,
                'duplicate' => $duplicates,
                'failed' => $failed,
                'duplicate_file' => $duplicateFilePath,
                'imported_by' => $importedBy,
                'client_id' => $clientId,
            ]);

            $log = ImportLog::create($logData);

            if ($log && $duplicateFilePath) {
                // Return secure route which uses ID
                $duplicateFileUrl = url('/api/secure/import-logs/' . $log->id . '/download-duplicates');
            }
        } catch (\Throwable $e) {
            // Log is secondary — do NOT re-throw
        }

        // ── 6. Write to import_histories (FAULT-TOLERANT) ─────────────────────
        $history = null;
        try {
            $histData = self::filterToExistingColumns('import_histories', [
                'module_name' => $moduleName,
                'action' => 'import',
                'file_name' => $fileName,
                'file_path' => $filePath,
                'imported_by' => $importedBy,
                'successful_rows' => $inserted,
                'failed_rows' => $failed,
                'duplicates_count' => $duplicates,
                'total_rows' => $totalRows,
                'duplicate_file' => $duplicateFilePath,
            ]);

            $history = ImportHistory::create($histData);
        } catch (\Throwable $e) {
            // History is secondary — do NOT re-throw
        }

        // ── 7. Return structured result to controller ──────────────────────────
        return [
            'success' => true,
            'inserted' => $inserted,
            'failed' => $failed,
            'duplicates' => $duplicates,
            'history' => $history,
            'log' => $log,
            'importer' => $importer,
            'duplicate_file' => $duplicateFilePath,
            'duplicate_file_url' => $duplicateFileUrl,
        ];
    }

    /**
     * Filter an associative data array to only include keys that correspond
     * to actual columns in the given table.
     *
     * This prevents "Unknown column" SQL errors when the schema and code
     * are temporarily out of sync during deployments.
     *
     * @param  string  $table
     * @param  array   $data
     * @return array
     */
    private static function filterToExistingColumns(string $table, array $data): array
    {
        static $columnCache = [];

        // Cache the column list per table within a single request lifecycle
        if (!isset($columnCache[$table])) {
            try {
                $columnCache[$table] = array_column(
                    \Illuminate\Support\Facades\DB::select("DESCRIBE `{$table}`"),
                    'Field'
                );
            } catch (\Throwable $e) {
                // Can't read schema — return the full data and let MySQL decide
                return $data;
            }
        }

        return array_intersect_key($data, array_flip($columnCache[$table]));
    }
}
