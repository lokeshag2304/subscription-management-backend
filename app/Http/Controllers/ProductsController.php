<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\CustomCipherService;
use App\Services\ActivityLogger;
use App\Models\Product;
use App\Models\ImportHistory;


use App\Services\AuditFileService;

class ProductsController extends Controller
{
    use \App\Traits\DataNormalizer;


   public function storeProducts(Request $request)
{
    $data = json_decode($request->getContent(), true);
    Log::info('Product store request', ['url' => $request->fullUrl(), 'payload' => $data]);

    $domainName = isset($data['name']) ? self::normalizeData(trim($data['name']), 'Product') : null;

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
                'success' => true,
                'status'  => 'exists',
                'message' => 'Product already exists',
                'domain_id' => $p->id
            ], 200);
        }
    }

    $encryptedName = CryptService::encryptData($domainName);

    $record = Product::create([
        'name' => $encryptedName,
    ]);

    $itemData = [
        'id'         => $record->id,
        'name'       => $domainName,
        'created_at' => $record->created_at,
    ];

    $itemData['created_at'] = DateFormatterService::formatDateTime($record->created_at);

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Product Added"),
        's_action'   => CustomCipherService::encryptData("Product Added"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData("Product added with name " . $domainName),
        's_message'  => CustomCipherService::encryptData("Product added with name " . $domainName),
        'details'    => CryptService::encryptData(json_encode(['product_id' => $record->id, 'name' => $domainName])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $uObj = DB::table('superadmins')->where('id', $s_id)->first();
    ActivityLogger::logActivity(
        $uObj, 
        'CREATE', 
        'Products', 
        'products', 
        $record->id, 
        null, 
        ['product' => $domainName], 
        "Product created : {$domainName}", 
        request()
    );

    return response()->json([
        'success' => true,
        'status'  => true,
        'message' => 'Product added successfully',
        'product_id' => $record->id,
        'data'    => $itemData
    ], 201);
}

public function updateProducts(Request $request)
{
    $data = json_decode($request->getContent(), true);
    Log::info('Product update request', ['url' => $request->fullUrl(), 'payload' => $data]);

    $productId  = $data['id'] ?? null;
    $newName    = isset($data['name']) ? self::normalizeData(trim($data['name']), 'Product') : null;

    $s_id       = $data['s_id'] ?? null;

    if (!$productId || !$newName || !$s_id) {
        return response()->json([
            'success' => false,
            'message' => 'id, name and s_id are required',
        ], 422);
    }

    $domain = Product::find($productId);

    if (!$domain) {
        return response()->json([
            'success' => false,
            'message' => 'Product not found',
        ], 404);
    }

    $allProducts = Product::all();
    foreach ($allProducts as $p) {
        if ($p->id == $productId) continue;
        try {
            $decName = CryptService::decryptData($p->name);
        } catch (\Exception $e) {
            $decName = $p->name;
        }
        if (strtolower(trim($decName)) === strtolower(trim($newName))) {
            return response()->json([
                'success' => true,
                'status'  => 'exists',
                'message' => 'Product already exists',
                'id'      => $p->id
            ], 200);
        }
    }

    try {
        $oldName = CryptService::decryptData($domain->name);
    } catch (\Exception $e) {
        $oldName = $domain->name;
    }

    $encryptedNewName = CryptService::encryptData($newName);

    $domain->update([
        'name' => $encryptedNewName
    ]);

    $itemData = [
        'id'         => $domain->id,
        'name'       => $newName,
        'created_at' => DateFormatterService::formatDateTime($domain->created_at),
    ];

    $changeMessage = "Product updated: OLD -> {$oldName} , NEW -> {$newName}";

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Products Updated"),
        's_action'   => CustomCipherService::encryptData("Products Updated"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData($changeMessage),
        's_message'  => CustomCipherService::encryptData($changeMessage),
        'details'    => CryptService::encryptData(json_encode(['product_id' => $productId, 'old_name' => $oldName, 'new_name' => $newName])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $uObj = DB::table('superadmins')->where('id', $s_id)->first();
    // Using HTML-like format for strike-through if supported by frontend, else clear arrow
    $displayDescription = "{$oldName} -> {$newName}";
    
    ActivityLogger::logActivity(
        $uObj, 
        'UPDATE', 
        'Products', 
        'products', 
        $productId,
        ['product' => $oldName], 
        ['product' => $newName], 
        $displayDescription, 
        request()
    );

    return response()->json([
        'success' => true,
        'status'  => true,
        'message' => 'Product updated successfully.',
        'data'    => $itemData
    ]);
}

public function ProductsList(Request $request)
{
    $page        = $request->input('page');
    // If no page provided, default to 1. If 0 provided, treat as 1.
    $page        = $page !== null && (int)$page > 0 ? (int)$page : 1;
    
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc'); 
    $orderBy     = $request->input('orderBy', 'name'); 
    $search      = strtolower($request->input('search', ''));

    $allProductsRaw = DB::table('products')->get();
    
    // Automatically clear "problems" (garbage records from binary imports)
    foreach ($allProductsRaw as $p) {
        try {
            $name = CryptService::decryptData($p->name);
            if (str_contains($name, 'xl/') || str_contains($name, 'xml') || strlen($name) > 500) {
                DB::table('products')->where('id', $p->id)->delete();
                DB::table('subscriptions')->where('product_id', $p->id)->delete();
            }
        } catch (\Exception $e) {}
    }

    $allProducts = DB::table('products')
        ->get()
        ->map(function ($item) {
            try {
                $item->name = CryptService::decryptData($item->name);
            } catch (\Exception $e) {}

            $item->updated_at = DateFormatterService::formatDateTime($item->updated_at ?? $item->created_at);
            $item->last_updated = $item->updated_at;
            $item->created_at = DateFormatterService::formatDateTime($item->created_at);
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
        if ($orderBy === 'id') {
            return (int) $item->id;
        }
        return strtolower($item->{$orderBy} ?? '');
    }, SORT_REGULAR, $order === 'desc')->values();

    $total = $allProducts->count();

    $offset = ($page - 1) * $rowsPerPage;
    $paged = $allProducts->slice($offset, $rowsPerPage)->values();

    return response()->json([
        'rows'  => $paged,
        'total' => $total,
        'current_page' => $page
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
        'details'    => CryptService::encryptData(json_encode(['ids' => $domainIds, 'names' => $domainNames])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $uObj = DB::table('superadmins')->where('id', $deletedBy)->first();
    $description = (count($domainNames) > 1) 
        ? "Products Deleted : " . implode(', ', $domainNames)
        : "Product Deleted : " . $domainNamesString;

    ActivityLogger::logActivity(
        $uObj, 
        'DELETE', 
        'Products', 
        'products', 
        null, 
        ['product' => $domainNamesString], 
        null, 
        $description, 
        request()
    );

    return response()->json([
        'success' => true,
        'message' => 'Products deleted successfully.',
        'deleted_products' => $domainNames
    ]);
}
    public function logExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'total_records' => 'required|integer',
            'data_snapshot' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $moduleName = 'Products';
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

        $userId = is_object($user) ? $user->id : ($user->id ?? $request->input('s_id'));

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

    public function import(Request $request)
    {
        $file = $request->file('file');
        $recordType = $request->input('record_type', 11);
        $sId = $request->input('s_id');

        if (!$file) {
            return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        try {
            $data = \App\Services\AuditFileService::parseFile($file);
            if (empty($data)) {
                return response()->json(['success' => false, 'message' => 'File is empty'], 400);
            }

            $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
            if (!$forceImport) {
                $rowNum = 1;
                $issues = [];
                foreach ($data as $row) {
                    $rowNum++;
                    $name = $row['name'] ?? $row['product_name'] ?? $row['product name'] ?? $row['product'] ?? null;
                    
                    if (!$name) {
                        $issues[] = ['row' => $rowNum, 'missing_fields' => ['name']];
                    }
                }

                if (!empty($issues)) {
                    $user = auth()->user();
                    $history = \App\Models\ImportHistory::create([
                        'module_name' => 'Products', 
                        'action' => 'IMPORT', 
                        'file_name' => $file->getClientOriginalName(), 
                        'imported_by' => $user->name ?? 'System / Admin', 
                        'successful_rows' => 0, 
                        'failed_rows' => count($issues), 
                        'duplicates_count' => 0,
                        'data_snapshot' => json_encode($issues)
                    ]);
                    \App\Services\AuditFileService::storeImport($history, $file);

                    \App\Services\ActivityLogger::imported($user->id ?? 1, 'Products', 0, $history->id, count($issues), 0);

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
            $rows = [];

            foreach ($data as $index => $row) {
                // Try to find product name in various possible lowercase header formats
                $name = $row['name'] ?? $row['product_name'] ?? $row['product name'] ?? $row['product'] ?? null;

                if (!$name) {
                    $failed++;
                    continue;
                }

                $normalizedName = self::normalizeData(trim($name), 'Product');
                
                // Check if product exists (decrypted comparison)
                $exists = false;
                $allProducts = Product::all();
                foreach ($allProducts as $p) {
                    try {
                        $dec = CryptService::decryptData($p->name);
                        if (strtolower(trim($dec)) === strtolower(trim($normalizedName))) {
                            $exists = true;
                            break;
                        }
                    } catch (\Exception $e) {}
                }

                if ($exists) {
                    $duplicates++;
                    $duplicateRows[] = $row;
                    continue;
                }

                $encryptedName = CryptService::encryptData($normalizedName);
                Product::create(['name' => $encryptedName]);
                
                $rows[] = ['Name' => $normalizedName];
                $inserted++;
            }

            // Log the import
            $history = AuditFileService::logImport($sId, 'Products', $inserted, $duplicates, $failed, $rows);
            \App\Services\AuditFileService::storeImport($history, $file);

            if ($duplicates > 0 && !empty($data)) {
                $headers = array_keys($data[0]);
                \App\Services\AuditFileService::storeDuplicates($history, $headers, $duplicateRows);
            }
            
            $user = DB::table('superadmins')->where('id', $sId)->first();
            ActivityLogger::logActivity(
                $user,
                'IMPORT',
                'Products',
                'products',
                $history->id,
                null,
                ['count' => $inserted],
                "Imported {$inserted} products",
                $request
            );

            return response()->json([
                'success' => true,
                'inserted' => $inserted,
                'duplicates' => $duplicates,
                'failed' => $failed,
                'history_id' => $history->id
            ]);

        } catch (\Exception $e) {
            Log::error('Product import error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error processing file: ' . $e->getMessage()], 500);
        }
    }
}
