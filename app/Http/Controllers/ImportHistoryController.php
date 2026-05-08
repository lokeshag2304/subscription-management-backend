<?php

namespace App\Http\Controllers;

use App\Models\ImportHistory;
use App\Http\Requests\StoreImportHistoryRequest;
use App\Http\Requests\UpdateImportHistoryRequest;
use App\Services\CryptService;

class ImportHistoryController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $entity = $request->query('entity');

        $query = ImportHistory::whereIn('action', ['IMPORT', 'EXPORT']);

        if ($entity) {
            $query->where('module_name', 'LIKE', '%' . $entity . '%');
        }

        $history = $query->orderBy('created_at', 'desc')
            ->paginate(10);
        
        $history->getCollection()->transform(function ($item) {
            $item->badge_color = strtolower($item->action) === 'import' ? 'green' : 'blue';
            $item->user_name = $item->imported_by;
            try {
                $dec = \App\Services\CryptService::decryptData($item->imported_by);
                if ($dec && $dec !== $item->imported_by) $item->user_name = $dec;
            } catch (\Exception $e) {}
            $item->userName = $item->user_name; // Map both for frontend compatibility
            $item->role = $item->role ?? 'Unknown';
            $item->inserted = (int)$item->successful_rows;
            $item->failed = (int)$item->failed_rows;
            $item->duplicates = (int)$item->duplicates_count;
            $item->inserted_count = (int)$item->successful_rows;
            $item->failed_count = (int)$item->failed_rows;
            $item->total_records = (int)($item->successful_rows ?? $item->inserted_count ?? 0);

            // Add file size if path exists
            $item->file_size = 0;
            if ($item->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($item->file_path)) {
                $item->file_size = \Illuminate\Support\Facades\Storage::disk('local')->size($item->file_path);
            }

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    public function download($id)
    {
        return \App\Services\AuditFileService::download($id);
    }

    public function destroy($id)
    {
        $history = ImportHistory::find($id);
        if (!$history) {
            return response()->json(['success' => false, 'message' => 'History record not found'], 404);
        }

        $history->delete();

        return response()->json(['success' => true, 'message' => 'History record deleted successfully']);
    }
}
