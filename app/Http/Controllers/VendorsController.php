<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\CustomCipherService;
use App\Services\ActivityLogger;
use App\Models\Vendor;
use App\Models\ImportHistory;


use App\Services\AuditFileService;

class VendorsController extends Controller
{
    use \App\Traits\DataNormalizer;
 
    private function logActivity($action, $record, $oldData = null, $newData = null)
    {
        try {
            $user = auth()->user() ?: (object)['id' => request()->input('s_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];
            
            $standardize = function($data) {
                if (!$data) return $data;
                $arr = is_array($data) ? $data : (method_exists($data, 'toArray') ? $data->toArray() : (array)$data);
                
                $decrypt = function($val) {
                    if (!$val || !is_string($val)) return $val;
                    try { return CryptService::decryptData($val) ?? $val; } catch (\Exception $e) { return $val; }
                };
 
                if (isset($arr['name'])) $arr['Name'] = $decrypt($arr['name']);
                return $arr;
            };
 
            ActivityLogger::logActivity(
                $user, 
                strtoupper($action), 
                'Vendors', 
                'vendors', 
                $record->id ?? null, 
                $standardize($oldData), 
                $standardize($newData), 
                null, 
                request()
            );
        } catch (\Exception $e) {}
    }


public function storeVendors(Request $request)
{
    try {

        $data = json_decode($request->getContent(), true);

        $vendorName = isset($data['name']) ? self::normalizeData(trim($data['name']), 'Vendor') : null;

        $s_id       = $data['s_id'] ?? null;

        if (empty($vendorName) || empty($s_id)) {
            return Response::json([
                'success' => false,
                'message' => 'name and s_id are required',
            ], 422);
        }

        // =========================
        // CHECK FOR DUPLICATES
        // =========================
        $allVendors = DB::table('vendors')->get();
        foreach ($allVendors as $v) {
            try {
                $decName = CryptService::decryptData($v->name);
            } catch (\Exception $e) {
                $decName = $v->name;
            }
            if (strtolower(trim($decName)) === strtolower(trim($vendorName))) {
                return Response::json([
                    'status'  => 'exists',
                    'success' => false,
                    'message' => $vendorName . ' already exist in the vendor.',
                    'id'      => $v->id,
                ]);
            }
        }

        // =========================
        // ENCRYPT VENDOR NAME
        // =========================
        $encryptedName = CryptService::encryptData($vendorName);

        // =========================
        // INSERT INTO VENDORS
        // =========================
        $record = Vendor::create([
            'name' => $encryptedName,
        ]);

        $itemData = [
            'id'         => $record->id,
            'name'       => $vendorName,
            'created_at' => DateFormatterService::formatDateTime($record->created_at),
            'updated_at' => DateFormatterService::formatDateTime($record->updated_at),
            'last_updated'=> DateFormatterService::formatDateTime($record->updated_at),
        ];

        // =========================
        // ACTIVITY LOG
        // =========================
        $plainMessage = "Vendor added with name {$vendorName}";

        DB::table('activities')->insert([
            'action'     => CryptService::encryptData("Vendor Added"),
            's_action'   => CustomCipherService::encryptData("Vendor Added"),
            'user_id'    => $s_id,
            'message'    => CryptService::encryptData($plainMessage),
            's_message'  => CustomCipherService::encryptData($plainMessage),
            'details'    => CryptService::encryptData(json_encode(['vendor_id' => $record->id, 'name' => $vendorName])),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        $uObj = DB::table('superadmins')->where('id', $s_id)->first();
        ActivityLogger::logActivity($uObj, 'CREATE', 'Vendors', 'vendors', $record->id, null, ['vendor' => $vendorName], "Vendor created : {$vendorName}", $request);

        return Response::json([
            'success'   => true,
            'status'    => true,
            'message'   => 'Vendor added successfully',
            'vendor_id' => $record->id,
            'data'      => $itemData
        ]);

    } catch (\Exception $e) {

        return Response::json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


public function updateVendors(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $vendorId   = $data['id'] ?? null;
    $vendorName = isset($data['name']) ? self::normalizeData(trim($data['name']), 'Vendor') : null;

    $s_id       = $data['s_id'] ?? null;

    if (empty($vendorName) || empty($vendorId) || empty($s_id)) {
        return Response::json([
            'success' => false,
            'message' => 'id, name and s_id are required',
        ], 422);
    }

    $vendor = DB::table('vendors')->where('id', $vendorId)->first();
    if (!$vendor) {
        return Response::json([
            'success' => false,
            'message' => 'Vendor not found',
        ], 404);
    }

    // CHECK FOR DUPLICATES (excluding current)
    $allVendors = DB::table('vendors')->where('id', '!=', $vendorId)->get();
    foreach ($allVendors as $v) {
        try {
            $decName = CryptService::decryptData($v->name);
        } catch (\Exception $e) {
            $decName = $v->name;
        }
        if (strtolower(trim($decName)) === strtolower(trim($vendorName))) {
            return Response::json([
                'status'  => 'exists',
                'success' => false,
                'message' => $vendorName . ' already exist in vendors.',
                'id'      => $v->id,
            ]);
        }
    }

    $oldName = "";
    try {
        $oldName = CryptService::decryptData($vendor->name);
    } catch (\Exception $e) {
        $oldName = $vendor->name;
    }

    $encryptedName = CryptService::encryptData($vendorName);

    Vendor::where('id', $vendorId)->update([
        'name' => $encryptedName,
    ]);

    $updatedRecord = Vendor::find($vendorId);
    $itemData = [
        'id'         => $updatedRecord->id,
        'name'       => $vendorName,
        'created_at' => DateFormatterService::formatDateTime($updatedRecord->created_at),
        'updated_at' => DateFormatterService::formatDateTime($updatedRecord->updated_at),
        'last_updated'=> DateFormatterService::formatDateTime($updatedRecord->updated_at),
    ];

    $activityMessage = "Vendor name updated from {$oldName} to {$vendorName}";
    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Vendor Updated"),
        's_action'   => CustomCipherService::encryptData("Vendor Updated"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData($activityMessage),
        's_message'  => CustomCipherService::encryptData($activityMessage),
        'details'    => CryptService::encryptData(json_encode(['vendor_id' => $vendorId, 'old_name' => $oldName, 'new_name' => $vendorName])),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $uObj = DB::table('superadmins')->where('id', $s_id)->first();
    ActivityLogger::logActivity($uObj, 'UPDATE', 'Vendors', 'vendors', $vendorId, ['vendor' => $oldName], ['vendor' => $vendorName], "{$oldName} -> {$vendorName}", $request);

    return Response::json([
        'success' => true,
        'status'  => true,
        'message' => 'Vendor updated successfully',
        'data'    => $itemData,
    ]);
}

public function VendorsList(Request $request)
{
    $page        = (int) $request->input('page', 0);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc'); 
    $orderBy     = $request->input('orderBy', 'name'); 
    $search      = strtolower($request->input('search', ''));

    $allVendors = DB::table('vendors')
        ->get()
        ->map(function ($item) {

            try {
                $item->name = CryptService::decryptData($item->name);
            } catch (\Exception $e) {}

            $item->updated_at = DateFormatterService::formatDateTime($item->updated_at ?? $item->created_at);
            $item->last_updated = $item->updated_at;
            $item->created_at = DateFormatterService::formatDateTime($item->created_at);
            return $item;
        });

    if ($search !== '') {
        $allVendors = $allVendors->filter(function ($item) use ($search) {
            return str_contains(strtolower($item->name), $search);
        })->values();
    }

    $allVendors = $allVendors->sortBy(function ($item) use ($orderBy) {
        return strtolower($item->{$orderBy});
    }, SORT_REGULAR, $order === 'desc')->values();

    $total = $allVendors->count();

    $paged = $allVendors->slice($page * $rowsPerPage, $rowsPerPage)->values();

    return Response::json([
        'rows'  => $paged,
        'total' => $total
    ]);
}

public function deleteVendors(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $vendorIds = $data['ids'] ?? [];
    $deletedBy = $data['s_id'] ?? null;

    if (empty($vendorIds) || !$deletedBy || !is_array($vendorIds)) {
        return Response::json([
            'success' => false,
            'message' => 'ids (array) and s_id are required',
        ], 422);
    }

    $vendors = DB::table('vendors')
        ->whereIn('id', $vendorIds)
        ->get();

    if ($vendors->isEmpty()) {
        return Response::json([
            'success' => false,
            'message' => 'No vendors found',
        ], 404);
    }

    $vendorNames = [];

    foreach ($vendors as $vendor) {
        try {
            $vendorNames[] = CryptService::decryptData($vendor->name);
        } catch (\Exception $e) {
            $vendorNames[] = $vendor->name;
        }
    }

    $vendorNamesString = implode(', ', $vendorNames);

    DB::table('vendors')->whereIn('id', $vendorIds)->delete();

    $activityMessage = "Vendors deleted: " . $vendorNamesString;

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Vendors Deleted"),
        's_action'   => CustomCipherService::encryptData("Vendors Deleted"),
        'user_id'    => $deletedBy,
        'message'    => CryptService::encryptData($activityMessage),
        's_message'  => CustomCipherService::encryptData($activityMessage),
        'details'    => CryptService::encryptData(json_encode(['ids' => $vendorIds, 'names' => $vendorNames])),
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);
    $uObj = DB::table('superadmins')->where('id', $deletedBy)->first();
    $description = (count($vendorNames) > 1) 
        ? "Vendors Deleted : " . implode(', ', $vendorNames)
        : "Vendor Deleted : " . $vendorNamesString;

    ActivityLogger::logActivity($uObj, 'DELETE', 'Vendors', 'vendors', null, ['vendor' => $vendorNamesString], null, $description, $request);

    return Response::json([
        'success' => true,
        'message' => 'Vendors deleted successfully.',
        'deleted_vendors' => $vendorNames
    ]);
}
    public function logExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'total_records' => 'required|integer',
            'data_snapshot' => 'required|array',
        ]);

        if ($validator->fails()) {
            return Response::json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $moduleName = 'Vendors';
        $user = Auth::user() ?? DB::table('superadmins')->where('id', $request->input('s_id'))->first();
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


        return Response::json([
            'success' => true,
            'message' => 'Export logged successfully',
            'history_id' => $history->id
        ]);
    }

    /**
     * Import vendors from CSV/Excel.
     */
    public function import(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            return Response::json(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $data = AuditFileService::parseFile($file);
        if (empty($data)) {
            return Response::json(['success' => false, 'message' => 'File is empty or invalid'], 400);
        }

        $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
        if (!$forceImport) {
            $rowNum = 1;
            $issues = [];
            foreach ($data as $row) {
                $rowNum++;
                $name = trim($row['name'] ?? $row['vendor name'] ?? $row['vendor'] ?? '');
                
                if (!$name) {
                    $issues[] = ['row' => $rowNum, 'missing_fields' => ['name']];
                }
            }

            if (!empty($issues)) {
                $s_id = $request->input('s_id') ?? 1;
                $user = DB::table('superadmins')->where('id', $s_id)->first();
                $history = \App\Models\ImportHistory::create([
                    'module_name' => 'Vendors', 
                    'action' => 'IMPORT', 
                    'file_name' => $file->getClientOriginalName(), 
                    'imported_by' => $user->name ?? 'System / Admin', 
                    'successful_rows' => 0, 
                    'failed_rows' => count($issues), 
                    'duplicates_count' => 0,
                    'data_snapshot' => json_encode($issues)
                ]);
                AuditFileService::storeImport($history, $file);

                ActivityLogger::imported($s_id, 'Vendors', 0, $history->id, count($issues), 0);

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

        // Pre-fetch all vendor names for duplicate check
        $allVendors = DB::table('vendors')->get()->map(function($v) {
            try {
                return strtolower(trim(CryptService::decryptData($v->name) ?? $v->name));
            } catch (\Exception $e) {
                return strtolower(trim($v->name));
            }
        })->filter()->toArray();

        foreach ($data as $row) {
            $name = trim($row['name'] ?? $row['vendor name'] ?? $row['vendor'] ?? '');
            
            if (!$name) {
                $failed++;
                continue;
            }

            if (in_array(strtolower($name), $allVendors)) {
                $duplicates++;
                $duplicateRows[] = $row;
                continue;
            }

            try {
                $encryptedName = CryptService::encryptData($name);
                $record = Vendor::create(['name' => $encryptedName]);
                
                $inserted++;
                $snapshot[] = ['name' => $name, 'id' => $record->id];
                $allVendors[] = strtolower($name); // Add to local list to prevent duplicates within the same file
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $s_id = $request->input('s_id') ?? 1;
        $uObj = DB::table('superadmins')->where('id', $s_id)->first();
        
        $history = AuditFileService::logImport($s_id, 'Vendors', $inserted, $duplicates, $failed, $snapshot);
        AuditFileService::storeImport($history, $file);

        if ($duplicates > 0 && !empty($data)) {
            $headers = array_keys($data[0]);
            AuditFileService::storeDuplicates($history, $headers, $duplicateRows);
        }

        ActivityLogger::imported($s_id, 'Vendors', $inserted, $history->id);

        return Response::json([
            'status'     => true,
            'success'    => true,
            'inserted'   => $inserted,
            'duplicates' => $duplicates,
            'failed'     => $failed,
            'history_id' => $history->id,
        ]);
    }
}
