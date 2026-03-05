<?php

namespace App\Services;

use App\Models\ImportHistory;
use App\Models\ImportLog;
use Maatwebsite\Excel\Facades\Excel;

/**
 * ImportService
 *
 * Handles the full import pipeline:
 *  1. Store the uploaded file
 *  2. Run the importer (which also exports duplicate rows to xlsx)
 *  3. Persist logs to import_logs + import_histories (fault-tolerant — never crash the import)
 *  4. Return structured result to controllers
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
        // ── 1. Store uploaded file (safe path for later download) ──────────────
        $file      = $request->file('file');
        $fileName  = $file->getClientOriginalName();
        $filePath  = null;

        try {
            $filePath = $file->store('imports');
        } catch (\Throwable $e) {
            // Non-fatal: if we can't store the file we still run the import
        }

        // ── 2. Tell importer its module name (used in duplicate xlsx filename) ─
        if (method_exists($importer, 'setModuleName')) {
            $importer->setModuleName($moduleName);
        }

        // ── 3. MAIN IMPORT — runs first, always completes ─────────────────────
        Excel::import($importer, $file);

        // All counters are now settled on $importer
        $inserted   = $importer->inserted   ?? 0;
        $duplicates = $importer->duplicates ?? 0;
        $failed     = $importer->failed     ?? 0;
        $totalRows  = $importer->totalRows  ?? ($inserted + $duplicates + $failed);

        $duplicateFilePath = $importer->duplicateFile ?? null;
        $duplicateFileUrl  = $duplicateFilePath
            ? url('/api/import-logs/download/' . ltrim($duplicateFilePath, '/'))
            : null;

        // ── 4. Resolve importing user (best-effort) ────────────────────────────
        $importedBy = 'System / Admin';
        $clientId   = null;

        try {
            $authUserId = $request->input('auth_user_id') ?? $request->attributes->get('auth_user_id') ?? $request->input('admin_id') ?? $request->input('client_id');

            if ($authUserId) {
                // Fetch user from DB since auth()->check() might be false for JWT stateless
                $user = \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $authUserId)->first();
                if ($user) {
                    $importedBy = $user->name ?? $user->email ?? ('User ID: ' . $user->id);
                    try {
                        $decrypted = \App\Services\CryptService::decryptData($importedBy);
                        if ($decrypted) $importedBy = $decrypted;
                    } catch (\Throwable $e) {}

                    // Identify if client
                    if ($user->login_type == 3) {
                        $clientId = $user->id;
                    }
                }
            }
        } catch (\Throwable $e) {}

        // ── 5. Write to import_logs (FAULT-TOLERANT) ──────────────────────────
        $log = null;
        try {
            // Build the insert data using only columns that exist in the table
            // This makes the insert safe even if future migrations haven't run yet
            $logData = self::filterToExistingColumns('import_logs', [
                'module'         => strtolower($moduleName),
                'file_name'      => $fileName,
                'total_rows'     => $totalRows,
                'inserted'       => $inserted,
                'duplicate'      => $duplicates,
                'failed'         => $failed,
                'duplicate_file' => $duplicateFilePath,
                'imported_by'    => $importedBy,
                'client_id'      => $clientId,
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
                'module_name'      => $moduleName,
                'action'           => 'import',
                'file_name'        => $fileName,
                'file_path'        => $filePath,
                'imported_by'      => $importedBy,
                'successful_rows'  => $inserted,
                'failed_rows'      => $failed,
                'duplicates_count' => $duplicates,
                'total_rows'       => $totalRows,
                'duplicate_file'   => $duplicateFilePath,
            ]);

            $history = ImportHistory::create($histData);
        } catch (\Throwable $e) {
            // History is secondary — do NOT re-throw
        }

        // ── 7. Return structured result to controller ──────────────────────────
        return [
            'history'            => $history,
            'log'                => $log,
            'importer'           => $importer,
            'duplicate_file'     => $duplicateFilePath,
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
