<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\ImportHistory;
use Illuminate\Support\Facades\Response;

class AuditFileService
{
    /**
     * Store exported data as a CSV and update history record.
     */
    public static function storeExport(ImportHistory $history, $data)
    {
        if (empty($data)) return null;

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data) || empty($data)) return null;

        try {
            $module = str_replace(' ', '_', $history->module_name ?? 'Export');
            $timestamp = date('Ymd_His');
            $fileName = "{$module}_Export_{$timestamp}.csv";
            $storagePath = "exports/{$fileName}";

            // Generate CSV content
            $handle = fopen('php://temp', 'r+');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

            // Headers
            if (isset($data[0]) && is_array($data[0])) {
                fputcsv($handle, array_keys($data[0]));
                // Rows
                foreach ($data as $row) {
                    fputcsv($handle, (array)$row);
                }
            }

            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);

            // Save to Storage
            Storage::disk('local')->put($storagePath, $csvContent);

            // Update History
            $history->update([
                'file_path' => $storagePath,
                'file_name' => $fileName,
                'data_snapshot' => json_encode($data) // Save snapshot as backup
            ]);

            return $storagePath;
        } catch (\Exception $e) {
            Log::error("AuditFileService::storeExport failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store imported file and update history record.
     */
    public static function storeImport(ImportHistory $history, $file)
    {
        try {
            $filePath = $file->store('imports');
            $history->update([
                'file_path' => $filePath,
                'file_name' => $file->getClientOriginalName()
            ]);
            return $filePath;
        } catch (\Exception $e) {
            Log::error("AuditFileService::storeImport failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store duplicate rows as a CSV and update history record.
     */
    public static function storeDuplicates(ImportHistory $history, array $header, array $rows)
    {
        if (empty($rows)) {
            Log::info("AuditFileService::storeDuplicates: No rows to store.");
            return null;
        }

        try {
            $timestamp = date('Ymd_His');
            $fileName = "duplicates_{$timestamp}.csv";
            $storagePath = "duplicates/{$fileName}";

            // Ensure directory exists
            if (!Storage::disk('local')->exists('duplicates')) {
                Storage::disk('local')->makeDirectory('duplicates');
            }

            // Generate CSV content
            $handle = fopen('php://temp', 'r+');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

            // Header
            fputcsv($handle, $header);
            // Rows
            foreach ($rows as $row) {
                fputcsv($handle, (array)$row);
            }

            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);

            // Save to Storage
            $saved = Storage::disk('local')->put($storagePath, $csvContent);
            
            if (!$saved) {
                Log::error("AuditFileService::storeDuplicates: Failed to write file to storage: $storagePath");
                return null;
            }

            // Update History - Explicitly
            $history->duplicate_file = $storagePath;
            $history->save();

            Log::info("AuditFileService::storeDuplicates: Saved duplicates to $storagePath for history ID: {$history->id}");

            return $storagePath;
        } catch (\Exception $e) {
            Log::error("AuditFileService::storeDuplicates failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log Export action to ImportHistory
     */
    public static function logExport($userId, $module, $count, $snapshot)
    {
        $history = ImportHistory::create([
            'admin_id' => $userId,
            'module_name' => $module,
            'action' => 'EXPORT',
            'total_records' => $count,
            'successful_rows' => $count,
            'data_snapshot' => json_encode($snapshot),
            'imported_by' => $userId, // Backward compatibility
            'created_at' => now()
        ]);

        self::storeExport($history, $snapshot);
        return $history;
    }

    /**
     * Store imported file to storage.
     */
    public static function saveImportFile($file)
    {
        try {
            return $file->store('imports');
        } catch (\Exception $e) {
            Log::error("AuditFileService::saveImportFile failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log Import action to ImportHistory
     * Supports both short-form (userId, module, inserted, duplicates, failed, snapshot)
     * and long-form (userId, module, fileName, filePath, inserted, failed, duplicates, userName, role)
     */
    public static function logImport($userId, $module, $insertedOrFileName = null, $duplicatesOrFilePath = null, $failedOrInserted = null, $snapshotOrFailed = null, $duplicates = 0, $userName = null, $role = null)
    {
        // Detect short-form call: 3rd arg is an int (inserted count) not a filename string
        if (is_int($insertedOrFileName) || is_null($insertedOrFileName)) {
            // Short-form: (userId, module, inserted, duplicates, failed, snapshot)
            $inserted  = (int) $insertedOrFileName;
            $duplicatesCount = (int) $duplicatesOrFilePath;
            $failed    = (int) $failedOrInserted;
            $snapshot  = $snapshotOrFailed;
            $fileName  = strtolower(str_replace(' ', '_', $module)) . '_import.csv';
            $filePath  = null;
            $importedBy = $userName ?? 'System / Admin';
            $roleVal   = $role ?? 'Superadmin';
        } else {
            // Long-form: (userId, module, fileName, filePath, inserted, failed, duplicates, userName, role)
            $fileName  = $insertedOrFileName;
            $filePath  = $duplicatesOrFilePath;
            $inserted  = (int) $failedOrInserted;
            $failed    = (int) $snapshotOrFailed;
            $duplicatesCount = (int) $duplicates;
            $importedBy = $userName ?? 'System / Admin';
            $roleVal   = $role ?? 'Superadmin';
            $snapshot  = null;
        }

        return ImportHistory::create([
            'admin_id'         => $userId,
            'module_name'      => $module,
            'action'           => 'IMPORT',
            'file_name'        => $fileName,
            'file_path'        => $filePath,
            'successful_rows'  => $inserted,
            'failed_rows'      => $failed,
            'duplicates_count' => $duplicatesCount,
            'imported_by'      => $importedBy,
            'role'             => $roleVal,
            'data_snapshot'    => is_array($snapshot) ? json_encode($snapshot) : $snapshot,
            'created_at'       => now()
        ]);
    }


    /**
     * Parse CSV or Excel file into array
     */
    public static function parseFile($file)
    {
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            // Enable auto-detection of line endings for different OS formats
            ini_set('auto_detect_line_endings', true);
            
            $handle = fopen($path, 'r');
            if (!$handle) return [];

            // Check for UTF-8 BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                return [];
            }

            // Clean headers: trim, lowercase, remove non-printable characters
            $header = array_map(function($h) {
                $cleaned = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
                return strtolower(trim($cleaned));
            }, $header);

            $data = [];
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty or purely whitespace rows
                if (empty($row) || (count($row) === 1 && $row[0] === null)) continue;
                
                // Trim all values in the row
                $row = array_map(fn($v) => $v === null ? '' : trim($v), $row);
                
                // Only process if the row has some data
                if (empty(array_filter($row, fn($v) => $v !== ''))) continue;

                if (count($header) === count($row)) {
                    $data[] = array_combine($header, $row);
                } else {
                    // Fallback: If counts don't match, try to pad or slice to match headers
                    $newRow = array_slice(array_pad($row, count($header), ''), 0, count($header));
                    $data[] = array_combine($header, $newRow);
                }
            }
            
            fclose($handle);
            return $data;
        }

        if ($extension === 'xlsx' || $extension === 'xls') {
            try {
                // Use Maatwebsite\Excel to parse Excel files
                // Passing null as the first argument gives us the raw array
                $dataArray = \Maatwebsite\Excel\Facades\Excel::toArray(new \stdClass(), $file);
                
                if (empty($dataArray) || empty($dataArray[0])) return [];
                
                $raw = $dataArray[0];
                $headerRow = array_shift($raw);
                if (!$headerRow) return [];

                // Clean headers
                $header = array_map(function($h) {
                    $cleaned = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string)$h);
                    return strtolower(trim($cleaned));
                }, $headerRow);

                $data = [];
                foreach ($raw as $row) {
                    // Skip empty rows
                    if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
                    
                    // Normalize row to match header count
                    $newRow = array_slice(array_pad($row, count($header), ''), 0, count($header));
                    $data[] = array_combine($header, $newRow);
                }
                
                return $data;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Excel parse failed: " . $e->getMessage());
                return [];
            }
        }

        return [];
    }

    /**
     * Handle the download logic with fallback.
     */
    public static function download($id)
    {
        $history = ImportHistory::find($id);

        if (!$history) {
            return response()->json(['error' => 'History record not found'], 404);
        }

        $disk = Storage::disk('local');
        $filePath = $history->file_path;
        $fileName = $history->file_name ?: "download_{$id}.csv";

        // 1. Try Physical File
        if ($filePath && $disk->exists($filePath)) {
            return response($disk->get($filePath))
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        }

        // 2. Fallback to Snapshot
        if (!empty($history->data_snapshot)) {
            $data = json_decode($history->data_snapshot, true);
            if (is_array($data) && !empty($data)) {
                $csvContent = "\xEF\xBB\xBF"; // BOM
                $handle = fopen('php://temp', 'r+');
                if (isset($data[0]) && is_array($data[0])) {
                    fputcsv($handle, array_keys($data[0]));
                    foreach ($data as $row) {
                        fputcsv($handle, (array)$row);
                    }
                }
                rewind($handle);
                $csvContent .= stream_get_contents($handle);
                fclose($handle);

                return response($csvContent)
                    ->header('Content-Type', 'text/csv')
                    ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
            }
        }

        return response()->json(['error' => 'File not found on server.'], 404);
    }
}
