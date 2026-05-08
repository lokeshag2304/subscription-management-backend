<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\ImportHistory;

class ExportPersister
{
    /**
     * Persist export data to a physical file and update the history record.
     *
     * @param ImportHistory $history
     * @param array $data
     * @return string|null The saved file path
     */
    public static function persist(ImportHistory $history, $data)
    {
        if (empty($data)) {
            return null;
        }

        // Handle JSON string if passed instead of array
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (!is_array($data) || empty($data)) {
            return null;
        }

        try {
            $module = str_replace(' ', '_', $history->module_name ?? 'Export');
            $timestamp = date('Ymd_His');
            $fileName = "{$module}_{$timestamp}_" . uniqid() . ".csv";
            $storagePath = "exports/" . $fileName;

            // Generate CSV content
            $handle = fopen('php://temp', 'r+');
            
            // Add UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Extract headers from the first row
            $firstRow = $data[0];
            fputcsv($handle, array_keys($firstRow));

            // Add data rows
            foreach ($data as $row) {
                fputcsv($handle, (array)$row);
            }

            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);

            // Save to local storage (storage/app/exports/...)
            Storage::disk('local')->put($storagePath, $csvContent);

            // Update the history record with the path
            $history->update([
                'file_path' => $storagePath,
                'file_name' => $fileName
            ]);

            return $storagePath;
        } catch (\Exception $e) {
            Log::error("ExportPersister failed: " . $e->getMessage());
            return null;
        }
    }
}
