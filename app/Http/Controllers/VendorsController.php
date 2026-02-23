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

    $domainId   = $data['id'] ?? null;
    $newName    = $data['name'] ?? null;
    $s_id       = $data['s_id'] ?? null;

    if (!$domainId || !$newName || !$s_id) {
        return response()->json([
            'success' => false,
            'message' => 'id, name and s_id are required',
        ], 422);
    }

    // Fetch existing domain
    $domain = DB::table('vendors')->where('id', $domainId)->first();

    if (!$domain) {
        return response()->json([
            'success' => false,
            'message' => 'vendors not found',
        ], 404);
    }

    // Decrypt old name
    try {
        $oldName = CryptService::decryptData($domain->name);
    } catch (\Exception $e) {
        $oldName = $domain->name;
    }

    // Encrypt new name
    $encryptedNewName = CryptService::encryptData($newName);

    // Update domain
    DB::table('products')
        ->where('id', $domainId)
        ->update([
            'name' => $encryptedNewName
        ]);

    $changeMessage = "vendors updated: OLD -> {$oldName} , NEW -> {$newName}";

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("vendors Updated"),
        's_action'   => CustomCipherService::encryptData("vendors Updated"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData($changeMessage),
        's_message'  => CustomCipherService::encryptData($changeMessage),
        'details'    => CryptService::encryptData(json_encode([
            'domain_id' => $domainId,
            'old_name'  => $oldName,
            'new_name'  => $newName,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'vendors updated successfully.',
    ]);
}

public function VendorsList(Request $request)
{
    $page        = (int) $request->input('page', 0);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc'); 
    $orderBy     = $request->input('orderBy', 'name'); 
    $search      = strtolower($request->input('search', ''));

    $allProducts = DB::table('vendors')
        ->get()
        ->map(function ($item) {

            try {
                $item->name = CryptService::decryptData($item->name);
            } catch (\Exception $e) {}

            $item->created_at = Carbon::parse($item->created_at)->format('M-d-Y h:i A');
            return $item;
        });

    if ($search !== '') {
        $allProducts = $allProducts->filter(function ($item) use ($search) {
            return str_contains(strtolower($item->name), $search);
        })->values();
    }

    $allProducts = $allProducts->sortBy(function ($item) use ($orderBy) {
        return strtolower($item->{$orderBy});
    }, SORT_REGULAR, $order === 'desc')->values();

    $total = $allProducts->count();

    $paged = $allProducts->slice($page * $rowsPerPage, $rowsPerPage)->values();

    return response()->json([
        'rows'  => $paged,
        'total' => $total
    ]);
}

public function deleteProducts(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $domainIds = $data['ids'] ?? [];
    $deletedBy = $data['s_id'] ?? null;

    if (empty($domainIds) || !$deletedBy || !is_array($domainIds)) {
        return response()->json([
            'success' => false,
            'message' => 'ids (array) and s_id are required',
        ], 422);
    }

    $domains = DB::table('vendors')
        ->whereIn('id', $domainIds)
        ->get();

    if ($domains->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No vendors found',
        ], 404);
    }

    $domainNames = [];

    foreach ($domains as $domain) {
        try {
            $domainNames[] = CryptService::decryptData($domain->name);
        } catch (\Exception $e) {
            $domainNames[] = $domain->name;
        }
    }

    $domainNamesString = implode(', ', $domainNames);

    DB::table('vendors')->whereIn('id', $domainIds)->delete();

    $activityMessage = "vendors deleted: " . $domainNamesString;

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("vendors Deleted"),
        's_action'   => CustomCipherService::encryptData("vendors Deleted"),
        'user_id'    => $deletedBy,
        'message'    => CryptService::encryptData($activityMessage),
        's_message'  => CustomCipherService::encryptData($activityMessage),
        'details'    => CryptService::encryptData(json_encode([
            'domain_ids' => $domainIds,
            'names'      => $domainNames,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'vendors deleted successfully.',
        'deleted_vendors' => $domainNames
    ]);
}
}
