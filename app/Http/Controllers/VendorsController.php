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
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\CustomCipherService;

class VendorsController extends Controller
{

public function storeVendors(Request $request)
{
    try {

        $data = json_decode($request->getContent(), true);

        $vendorName = $data['name'] ?? null;
        $s_id       = $data['s_id'] ?? null;

        if (empty($vendorName) || empty($s_id)) {
            return response()->json([
                'success' => false,
                'message' => 'name and s_id are required',
            ], 422);
        }

        // =========================
        // ENCRYPT VENDOR NAME
        // =========================
        $encryptedName = CryptService::encryptData($vendorName);

        // =========================
        // INSERT INTO VENDORS
        // =========================
        $vendorId = DB::table('vendors')->insertGetId([
            'name'       => $encryptedName,
            'created_at' => now(),
        ]);

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
            'details'    => CryptService::encryptData(json_encode([
                'vendor_id' => $vendorId,
                'name'      => $vendorName,
            ])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success'   => true,
            'message'   => 'Vendor added successfully',
            'vendor_id' => $vendorId
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


public function updateVendors(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $vendorId   = $data['id'] ?? null; // Changed from $domainId to $vendorId
    $newName    = $data['name'] ?? null;
    $s_id       = $data['s_id'] ?? null;

    if (!$vendorId || !$newName || !$s_id) { // Changed from $domainId to $vendorId
        return response()->json([
            'success' => false,
            'message' => 'id, name and s_id are required',
        ], 422);
    }

    // Fetch existing vendor
    $vendor = DB::table('vendors')->where('id', $vendorId)->first(); // Changed from $domain to $vendor

    if (!$vendor) { // Changed from $domain to $vendor
        return response()->json([
            'success' => false,
            'message' => 'Vendor not found', // Changed message
        ], 404);
    }

    // Decrypt old name
    try {
        $oldName = CryptService::decryptData($vendor->name); // Changed from $domain->name to $vendor->name
    } catch (\Exception $e) {
        $oldName = $vendor->name; // Changed from $domain->name to $vendor->name
    }

    // Encrypt new name
    $encryptedNewName = CryptService::encryptData($newName);

    // Update vendor
    DB::table('vendors')
        ->where('id', $vendorId)
        ->update([
            'name' => $encryptedNewName
        ]);

    $changeMessage = "Vendor updated: OLD -> {$oldName} , NEW -> {$newName}"; // Changed message

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Vendor Updated"), // Changed message
        's_action'   => CustomCipherService::encryptData("Vendor Updated"), // Changed message
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData($changeMessage),
        's_message'  => CustomCipherService::encryptData($changeMessage),
        'details'    => CryptService::encryptData(json_encode([
            'vendor_id' => $vendorId, // Changed from domain_id to vendor_id
            'old_name'  => $oldName,
            'new_name'  => $newName,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Vendor updated successfully.', // Changed message
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

            $item->updated_at = DateFormatterService::format($item->updated_at ?? $item->created_at);
            $item->last_updated = $item->updated_at;
            $item->created_at = DateFormatterService::format($item->created_at);
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

    return response()->json([
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
        return response()->json([
            'success' => false,
            'message' => 'ids (array) and s_id are required',
        ], 422);
    }

    $vendors = DB::table('vendors')
        ->whereIn('id', $vendorIds)
        ->get();

    if ($vendors->isEmpty()) {
        return response()->json([
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
        'details'    => CryptService::encryptData(json_encode([
            'vendor_ids' => $vendorIds,
            'names'      => $vendorNames,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Vendors deleted successfully.',
        'deleted_vendors' => $vendorNames
    ]);
}
}
