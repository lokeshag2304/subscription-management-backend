<?php

namespace App\Http\Controllers;

use App\Models\DomainName;
use App\Models\SuffixMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\ActivityLogger;
use App\Models\ImportHistory;
use App\Services\AuditFileService;


/**
 * DomainMasterController
 *
 * Handles CRUD for the domain_master table.
 * This is a simple master list: id, domain_name, created_at.
 * No encryption, no expiry, no renewal — just a clean name list.
 */
class DomainMasterController extends Controller
{
    /**
     * List all domain master records (paginated + searchable).
     * Supports: page, rowsPerPage, search, orderBy, order, all_ids
     */
    public function index(Request $request)
    {
        $limit   = (int) $request->input('rowsPerPage', $request->input('limit', 10));
        $page    = (int) $request->input('page', 1);
        if ($page < 1) $page = 1;

        $search  = $request->input('search', '');
        $order   = $request->input('order', 'desc');
        $orderBy = $request->input('orderBy', 'id');

        $query = DomainName::select('id', 'domain_name', 'created_at');

        if (!empty($search)) {
            $query->where('domain_name', 'LIKE', '%' . $search . '%');
        }

        // Return only IDs (for bulk-select-all)
        if ($request->input('all_ids')) {
            return response()->json([
                'status' => true,
                'ids'    => (clone $query)->pluck('id')->toArray(),
            ]);
        }

        $total = (clone $query)->count();
        $skip  = ($page - 1) * $limit;

        $rows = $query->orderBy($orderBy, $order)
            ->skip($skip)
            ->take($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'domain_name' => $item->domain_name,
                    'created_at'  => DateFormatterService::formatDateTime($item->created_at),
                ];
            });

        return response()->json([
            'status'  => true,
            'success' => true,
            'rows'    => $rows,
            'total'   => $total,
        ]);
    }

    /**
     * Create a new domain master record.
     */
    public function store(Request $request)
    {
        $domainName = trim($request->input('domain_name') ?? $request->input('name') ?? '');

        if (!$domainName) {
            return response()->json([
                'success' => false,
                'message' => 'Domain name is required',
            ], 400);
        }

        // Duplicate check
        if (DomainName::where('domain_name', $domainName)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Domain already exists in master list',
            ], 409);
        }

        // --- Suffix Validation ---
        $parts = explode('.', $domainName);
        if (count($parts) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid domain format (missing dot or extension)',
            ], 400);
        }
        $suffix = strtolower(end($parts));

        if (!SuffixMaster::where('suffix', $suffix)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "The extension \".$suffix\" is not in the managed list. Please add it to Suffix Master first.",
            ], 422);
        }
        // -------------------------

        $record = DomainName::create([
            'domain_name' => $domainName,
            'name'        => $domainName,
        ]);

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'CREATE', 'Domain Master', 'domain_master', $record->id, null,
            ['domain' => $domainName], "Domain Master created : {$domainName}", request());

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => 'Domain added to master list',
            'data'    => [
                'id'          => $record->id,
                'domain_name' => $record->domain_name,
                'created_at'  => DateFormatterService::formatDateTime($record->created_at),
            ],
        ], 201);
    }

    /**
     * Update a domain master record.
     */
    public function update(Request $request, $id)
    {
        $record = DomainName::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        }

        $domainName = trim($request->input('domain_name') ?? $request->input('name') ?? '');
        if (!$domainName) {
            return response()->json(['success' => false, 'message' => 'Domain name is required'], 400);
        }

        // --- Suffix Validation (for update) ---
        $parts = explode('.', $domainName);
        if (count($parts) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid domain format (missing dot or extension)',
            ], 400);
        }
        $suffix = strtolower(end($parts));

        if (!SuffixMaster::where('suffix', $suffix)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "The extension \".$suffix\" is not in the managed list. Please add it to Suffix Master first.",
            ], 422);
        }
        // --------------------------------------

        $oldName = $record->domain_name;
        $record->update([
            'domain_name' => $domainName,
            'name'        => $domainName,
        ]);

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'UPDATE', 'Domain Master', 'domain_master', $id,
            ['domain' => $oldName], ['domain' => $domainName],
            "{$oldName} -> {$domainName}", request());

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => 'Domain updated successfully',
            'data'    => [
                'id'          => $record->id,
                'domain_name' => $record->domain_name,
                'created_at'  => DateFormatterService::formatDateTime($record->created_at),
            ],
        ]);
    }

    /**
     * Delete a single domain master record.
     */
    public function destroy($id)
    {
        $record = DomainName::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'Record not found'], 404);
        }

        $domainNameVal = $record->domain_name;
        $record->delete();

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'DELETE', 'Domain Master', 'domain_master', $id,
            ['domain' => $domainNameVal], null, "Domain Master Deleted : {$domainNameVal}", request());

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => 'Domain deleted successfully',
        ]);
    }

    /**
     * Bulk delete domain master records.
     */
    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
        }

        $names = DomainName::whereIn('id', $ids)->pluck('domain_name')->implode(', ');
        DomainName::whereIn('id', $ids)->delete();

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        ActivityLogger::logActivity($uObj, 'DELETE', 'Domain Master', 'domain_master', null,
            ['domain' => $names], null, "Domain Master bulk deleted : {$names}", request());

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => count($ids) . ' domain(s) deleted successfully',
        ]);
    }

    /**
     * Fix legacy encrypted domain_name data.
     * Tries to decrypt existing encrypted strings in domain_master table.
     */
    public function decryptLegacyData(Request $request)
    {
        $rows = DomainName::all();
        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            // Source could be from domain_name or name column
            $source = $row->domain_name ?: $row->name;
            if (!$source) {
                $skipped++;
                continue;
            }

            try {
                // Try decrypting. If it's already plain, this usually throws or returns as-is.
                $decrypted = CryptService::decryptData($source);
                if ($decrypted && $decrypted !== $source) {
                    $row->update([
                        'domain_name' => $decrypted,
                        'name' => $decrypted
                    ]);
                    $fixed++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $skipped++;
                // If it fails to decrypt, it might already be plain text.
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Decrypt fix complete: $fixed records updated, $skipped skipped.",
            'fixed' => $fixed,
            'skipped' => $skipped
        ]);
    }
    public function logExport(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'total_records' => 'required|integer',
            'data_snapshot' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $moduleName = 'Domain Master';
        $user = auth()->user() ?? DB::table('superadmins')->where('id', $request->input('s_id'))->first();

        $userId = is_object($user) ? $user->id : ($user->id ?? $request->input('s_id'));
        $userName = $user ? (CryptService::decryptData($user->name) ?? $user->name) : 'System';
        $role = $user ? ($user->role ?? (isset($user->login_type) ? ($user->login_type === 1 ? 'Superadmin' : ($user->login_type === 3 ? 'Client' : 'User')) : 'Unknown')) : 'System';

        // 1. Create ImportHistory record via AuditFileService
        $history = AuditFileService::logExport(
            $userId,
            $moduleName,
            $request->total_records,
            $request->data_snapshot
        );

        // 2. Log Activity
        ActivityLogger::exported(
            $userId,
            $moduleName,
            $request->total_records,
            $history->id,
            $request
        );


        return response()->json([
            'success' => true,
            'message' => 'Export logged successfully',
            'history_id' => $history->id
        ]);
    }

    /**
     * Import domain master records from CSV.
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

        $suffixCache = \Illuminate\Support\Facades\DB::table('suffix_masters')->pluck('suffix')->toArray();

        $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
        if (!$forceImport) {
            $rowNum = 1;
            $issues = [];
            foreach ($data as $row) {
                $rowNum++;
                $domainName = trim($row['domain_name'] ?? $row['domain name'] ?? $row['domain'] ?? $row['name'] ?? '');
                
                if (!$domainName) {
                    $issues[] = ['row' => $rowNum, 'missing_fields' => ['domain_name']];
                } else {
                    $domainLower = strtolower($domainName);
                    if (!str_contains($domainLower, '.')) {
                        $issues[] = ['row' => $rowNum, 'missing_fields' => ["domain_name (invalid format: '$domainName'. Must contain a dot)"]];
                    } else {
                        $hasValidSuffix = false;
                        foreach ($suffixCache as $sfx) {
                            if (str_ends_with($domainLower, '.' . ltrim($sfx, '.'))) {
                                $hasValidSuffix = true;
                                break;
                            }
                        }
                        if (!$hasValidSuffix) {
                            $issues[] = ['row' => $rowNum, 'missing_fields' => ["domain_name (invalid suffix: '$domainName'. Suffix not in Suffix Master)"]];
                        }
                    }
                }
            }

            if (!empty($issues)) {
                $user = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
                $history = \App\Models\ImportHistory::create([
                    'module_name' => 'Domain Master', 
                    'action' => 'IMPORT', 
                    'file_name' => $file->getClientOriginalName(), 
                    'imported_by' => $user->name ?? 'System / Admin', 
                    'successful_rows' => 0, 
                    'failed_rows' => count($issues), 
                    'duplicates_count' => 0,
                    'data_snapshot' => json_encode($issues)
                ]);
                AuditFileService::storeImport($history, $file);

                ActivityLogger::imported($user->id ?? 1, 'Domain Master', 0, $history->id, count($issues), 0);

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
            $domainName = trim($row['domain_name'] ?? $row['domain name'] ?? $row['domain'] ?? $row['name'] ?? '');
            
            if (!$domainName) {
                $failed++;
                continue;
            }

            // Case-insensitive duplicate check
            if (DomainName::whereRaw('LOWER(domain_name) = ?', [strtolower($domainName)])->exists()) {
                $duplicates++;
                $duplicateRows[] = $row;
                continue;
            }

            // Domain validation in main loop
            $domainLower = strtolower($domainName);
            if (!str_contains($domainLower, '.')) {
                $failed++;
                continue;
            }
            $hasValidSuffix = false;
            foreach ($suffixCache as $sfx) {
                if (str_ends_with($domainLower, '.' . ltrim($sfx, '.'))) {
                    $hasValidSuffix = true;
                    break;
                }
            }
            if (!$hasValidSuffix) {
                $failed++;
                continue;
            }

            try {
                $record = DomainName::create([
                    'domain_name' => $domainName,
                    'name'        => $domainName,
                ]);
                $inserted++;
                $snapshot[] = ['domain_name' => $domainName, 'id' => $record->id];
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $uObj = auth()->user() ?? DB::table('superadmins')->where('login_type', 1)->first();
        $history = AuditFileService::logImport($uObj->id, 'Domain Master', $inserted, $duplicates, $failed, $snapshot);
        AuditFileService::storeImport($history, $file);

        if ($duplicates > 0 && !empty($data)) {
            $headers = array_keys($data[0]);
            AuditFileService::storeDuplicates($history, $headers, $duplicateRows);
        }

        ActivityLogger::imported($uObj->id, 'Domain Master', $inserted, $history->id);

        if ($inserted === 0 && $failed > 0) {
            return response()->json([
                'success' => false,
                'status' => false,
                'inserted' => 0,
                'failed' => $failed,
                'duplicates' => $duplicates,
                'message' => "Import failed: $failed rows had validation errors (invalid format or suffix)."
            ], 422);
        }

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

