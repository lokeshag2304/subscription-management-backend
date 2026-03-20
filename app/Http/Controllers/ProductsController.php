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
use Illuminate\Support\Facades\Log;

class ProductsController extends Controller
{

   public function storeProducts(Request $request)
{
    $data = json_decode($request->getContent(), true);
    Log::info('Product store request', ['url' => $request->fullUrl(), 'payload' => $data]);

    $domainName = isset($data['name']) ? trim($data['name']) : null;
    $s_id       = $data['s_id'] ?? null;

    if (!$domainName || !$s_id) {
        return response()->json([
            'success' => false,
            'message' => 'name and s_id are required',
        ], 422);
    }

    $allProducts = DB::table('products')->get();
    foreach ($allProducts as $p) {
        try {
            $decName = CryptService::decryptData($p->name);
        } catch (\Exception $e) {
            $decName = $p->name;
        }
        if (strtolower(trim($decName)) === strtolower(trim($domainName))) {
            return response()->json([
                'success' => false,
                'message' => $domainName . ' already exist in the product.',
            ], 409);
        }
    }

    $encryptedName = CryptService::encryptData($domainName);

    $ProductID = DB::table('products')->insertGetId([
        'name' => $encryptedName,
        'created_at' => now(),
    ]);

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Product Added"),
        's_action'   => CustomCipherService::encryptData("Product Added"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData("Product added: " . $domainName),
        's_message'  => CustomCipherService::encryptData("Product added: " . $domainName),
        'details'    => CryptService::encryptData(json_encode([
            'domain_id' => $ProductID,
            'name'      => $domainName,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Products added successfully.',
        'domain_id' => $ProductID
    ]);
}

public function updateProducts(Request $request)
{
    $data = json_decode($request->getContent(), true);
    Log::info('Product update request', ['url' => $request->fullUrl(), 'payload' => $data]);

    $domainId   = $data['id'] ?? null;
    $newName    = isset($data['name']) ? trim($data['name']) : null;
    $s_id       = $data['s_id'] ?? null;

    if (!$domainId || !$newName || !$s_id) {
        return response()->json([
            'success' => false,
            'message' => 'id, name and s_id are required',
        ], 422);
    }

    // Fetch existing domain
    $domain = DB::table('products')->where('id', $domainId)->first();

    if (!$domain) {
        return response()->json([
            'success' => false,
            'message' => 'Product not found',
        ], 404);
    }

    $allProducts = DB::table('products')->get();
    foreach ($allProducts as $p) {
        if ($p->id == $domainId) continue;
        try {
            $decName = CryptService::decryptData($p->name);
        } catch (\Exception $e) {
            $decName = $p->name;
        }
        if (strtolower(trim($decName)) === strtolower(trim($newName))) {
            return response()->json([
                'success' => false,
                'message' => $newName . ' already exist in the product.',
            ], 409);
        }
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

    $changeMessage = "products updated: OLD -> {$oldName} , NEW -> {$newName}";

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Products Updated"),
        's_action'   => CustomCipherService::encryptData("Products Updated"),
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
        'message' => 'Products updated successfully.',
    ]);
}

public function ProductsList(Request $request)
{
    $page        = (int) $request->input('page', 0);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc'); 
    $orderBy     = $request->input('orderBy', 'name'); 
    $search      = strtolower($request->input('search', ''));

    $allProducts = DB::table('products')
        ->get()
        ->map(function ($item) {
            try {
                $item->name = CryptService::decryptData($item->name);
            } catch (\Exception $e) {}

            $item->updated_at = DateFormatterService::format($item->updated_at ?? $item->created_at);
            $item->last_updated = $item->updated_at;
            $item->created_at = DateFormatterService::format($item->created_at);
            return $item;
        })
        ->unique(function ($item) {
            return strtolower(trim($item->name));
        })
        ->values();

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

    $domains = DB::table('products')
        ->whereIn('id', $domainIds)
        ->get();

    if ($domains->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No Products found',
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

    DB::table('products')->whereIn('id', $domainIds)->delete();

    $activityMessage = "Products deleted: " . $domainNamesString;

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Products Deleted"),
        's_action'   => CustomCipherService::encryptData("Products Deleted"),
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
        'message' => 'Products deleted successfully.',
        'deleted_products' => $domainNames
    ]);
}
}
