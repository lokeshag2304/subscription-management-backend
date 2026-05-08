<?php

namespace App\Http\Controllers;

use App\Models\SuffixMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ActivityLogger;

use App\Services\AuditFileService;
use App\Services\CryptService;

class SuffixMasterController extends Controller
{
    /**
     * List all suffixes.
     */
    public function index()
    {
        $suffixes = SuffixMaster::orderBy('suffix', 'asc')->get();
        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $suffixes
        ]);
    }

    /**
     * Store a new suffix.
     */
    public function store(Request $request)
    {
        $suffix = strtolower(trim($request->input('suffix') ?? ''));
        $suffix = ltrim($suffix, '.'); // Remove leading dot if any

        if (!$suffix) {
            return response()->json(['success' => false, 'message' => 'TLD is required'], 400);
        }

        if (SuffixMaster::where('suffix', $suffix)->exists()) {
            return response()->json(['success' => false, 'message' => 'Duplicate TLD already exists'], 422);
        }

        $record = SuffixMaster::create(['suffix' => $suffix]);

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'CREATE', 'TLD’s Management', 'suffix_masters', $record->id, null,
            ['suffix' => $suffix], "TLD added : .{$suffix}", request());

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'TLD added successfully',
            'data' => $record
        ]);
    }

    /**
     * Remove a suffix.
     */
    public function destroy($id)
    {
        $record = SuffixMaster::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'TLD not found'], 404);
        }

        $suffixVal = $record->suffix;
        $record->delete();

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'DELETE', 'TLD’s Management', 'suffix_masters', $id,
            ['suffix' => $suffixVal], null, "Deleted TLD: {$suffixVal}", request());

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'TLD deleted successfully'
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
        }

        // Fetch names before deletion to log them
        $suffixes = SuffixMaster::whereIn('id', $ids)->pluck('suffix')->toArray();
        $count = count($suffixes);

        if ($count === 0) {
            return response()->json(['success' => false, 'message' => 'No records found to delete'], 404);
        }

        // Prepare a readable string of deleted suffixes (limit to 10 for readability in logs)
        $displayNames = array_slice($suffixes, 0, 10);
        $namesString = implode(', ', $displayNames);
        if ($count > 10) {
            $namesString .= " ...and " . ($count - 10) . " more";
        }

        $label = $count === 1 ? "Deleted TLD" : "Deleted TLD's";
        $logMessage = "{$label}: {$namesString}";

        SuffixMaster::whereIn('id', $ids)->delete();

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'DELETE_BULK', 'TLD’s Management', 'suffix_masters', null,
            ['count' => $count, 'ids' => $ids, 'names' => $suffixes], null, $logMessage, request());

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => "{$count} TLD’s deleted successfully"
        ]);
    }

    /**
     * Log an export action and store the snapshot.
     */
    public function logExport(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'total_records' => 'required|integer',
            'data_snapshot' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $moduleName = 'TLD’s Management';
        $user = auth()->user() ?? DB::table('superadmins')->where('id', $request->input('s_id'))->first();

        $userId = is_object($user) ? $user->id : ($user->id ?? $request->input('s_id') ?? 1);

        // 1. Create History record
        $history = AuditFileService::logExport(
            $userId,
            $moduleName,
            $request->total_records,
            $request->data_snapshot
        );

        // 2. Log Activity
        ActivityLogger::exported($userId, $moduleName, $request->total_records, $history->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Export logged successfully',
            'history_id' => $history->id
        ]);
    }

    /**
     * Import suffixes from file.
     */
    public function import(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $data = AuditFileService::parseFile($file);
        if (empty($data)) {
            return response()->json(['success' => false, 'message' => 'File is empty or invalid'], 400);
        }

        $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
        if (!$forceImport) {
            $rowNum = 1;
            $issues = [];
            foreach ($data as $row) {
                $rowNum++;
                $suffix = strtolower(trim($row['suffix'] ?? $row['extension'] ?? ''));
                if (!$suffix) {
                    $issues[] = ['row' => $rowNum, 'missing_fields' => ['suffix']];
                }
            }

            if (!empty($issues)) {
                $user = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
                $history = \App\Models\ImportHistory::create([
                    'module_name' => 'TLD’s Management', 
                    'action' => 'IMPORT', 
                    'file_name' => $file->getClientOriginalName(), 
                    'imported_by' => $user->name ?? 'System / Admin', 
                    'successful_rows' => 0, 
                    'failed_rows' => count($issues), 
                    'duplicates_count' => 0,
                    'data_snapshot' => json_encode($issues)
                ]);
                AuditFileService::storeImport($history, $file);

                ActivityLogger::imported($user->id ?? 1, 'TLD’s Management', 0, $history->id, count($issues), 0);

                return response()->json([
                    'success' => false,
                    'requires_confirmation' => true,
                    'message' => 'Validation failed: Mandatory fields are missing.',
                    'issues' => $issues,
                    'history_id' => $history->id,
                    'total_affected' => count($issues)
                ], 422);
            }
        }

        $inserted = 0;
        $duplicates = 0;
        $duplicateRows = [];
        $failed = 0;
        $snapshot = [];

        foreach ($data as $row) {
            $suffix = strtolower(trim($row['suffix'] ?? $row['extension'] ?? ''));
            $suffix = ltrim($suffix, '.');

            if (!$suffix) {
                $failed++;
                continue;
            }

            if (SuffixMaster::where('suffix', $suffix)->exists()) {
                $duplicates++;
                $duplicateRows[] = $row;
                continue;
            }

            try {
                $record = SuffixMaster::create(['suffix' => $suffix]);
                $inserted++;
                $snapshot[] = ['suffix' => $suffix, 'id' => $record->id];
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        $history = AuditFileService::logImport($uObj->id, 'TLD’s Management', $inserted, $duplicates, $failed, $snapshot);
        AuditFileService::storeImport($history, $file);

        if ($duplicates > 0 && !empty($data)) {
            $headers = array_keys($data[0]);
            AuditFileService::storeDuplicates($history, $headers, $duplicateRows);
        }

        ActivityLogger::imported($uObj->id, 'TLD’s Management', $inserted, $history->id);

        return response()->json([
            'status'     => true,
            'success'    => true,
            'inserted'   => $inserted,
            'duplicates' => $duplicates,
            'failed'     => $failed,
            'history_id' => $history->id,
        ]);
    }
}
