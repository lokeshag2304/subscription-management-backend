<?php

namespace App\Http\Controllers;

use App\Models\ImportHistory;
use App\Http\Requests\StoreImportHistoryRequest;
use App\Http\Requests\UpdateImportHistoryRequest;

class ImportHistoryController extends Controller
{
    public function index()
    {
        $history = ImportHistory::orderBy('created_at', 'desc')->paginate(10);
        
        $history->getCollection()->transform(function ($item) {
            $item->badge_color = strtolower($item->action) === 'import' ? 'green' : 'blue';
            $item->user_name = $item->imported_by;
            $item->inserted = (int)$item->successful_rows;
            $item->failed = (int)$item->failed_rows;
            $item->duplicates = (int)$item->duplicates_count;
            $item->inserted_count = (int)$item->successful_rows;
            $item->failed_count = (int)$item->failed_rows;
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    public function download($id)
    {
        $history = ImportHistory::findOrFail($id);
        
        $filePath = $history->file_path;
        
        // Log for debugging
        \Illuminate\Support\Facades\Log::info("Download attempt for ID: {$id}", [
            'path' => $filePath,
            'exists_local' => \Illuminate\Support\Facades\Storage::disk('local')->exists($filePath),
            'exists_default' => \Illuminate\Support\Facades\Storage::exists($filePath),
            'full_path' => storage_path('app/' . $filePath)
        ]);
        
        if (!$filePath) {
            return response()->json(['success' => false, 'message' => 'No file path found for this record.'], 404);
        }

        $fullPath = storage_path('app/' . $filePath);
        
        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($filePath) && !file_exists($fullPath)) {
            return response()->json([
                'success' => false, 
                'message' => 'File not found on server.',
                'path' => $filePath,
                'full_path' => $fullPath
            ], 404);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($filePath, $history->file_name);
    }
}
