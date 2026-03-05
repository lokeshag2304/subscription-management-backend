<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;
use App\Services\ClientScopeService;

class DropdownsController extends Controller
{

public function getDomains(Request $request)
{
    $query = DB::table('domains')
        ->select('id', 'name', 'client_id');
    
    ClientScopeService::applyScope($query, $request);

    $domains = $query->orderBy('id','desc')
        ->get()
        ->map(function ($domain) {
            try {
                $domain->name = CryptService::decryptData($domain->name) ?? $domain->name;
            } catch (\Exception $e) {}
            return $domain;
        });

    return response()->json([
        'status' => true,
        'message' => 'Domains fetched successfully',
        'data' => $domains
    ]);
}

public function getProduct(Request $request)
{
    // Products are usually global, but if they are per-client, apply scope.
    // For now, let's keep it global as it's a "Category".
    $products = DB::table('products')
        ->select('id', 'name')
        ->orderBy('id','desc')
        ->get()
        ->map(function ($product) {
            try {
                $product->name = CryptService::decryptData($product->name) ?? $product->name;
            } catch (\Exception $e) {}
            return $product;
        });

    return response()->json([
        'status' => true,
        'message' => 'products fetched successfully',
        'data' => $products
    ]);
}

public function getVendors(Request $request)
{
    // Vendors are usually global.
    $vendors = DB::table('vendors')
        ->select('id', 'name')
        ->orderBy('id','desc')
        ->get()
        ->map(function ($vendor) {
            try {
                $vendor->name = CryptService::decryptData($vendor->name) ?? $vendor->name;
            } catch (\Exception $e) {}
            return $vendor;
        });

    return response()->json([
        'status' => true,
        'message' => 'vendors fetched successfully',
        'data' => $vendors
    ]);
}

public function getClients(Request $request)
{
    $query = DB::table('superadmins')
        ->select('id', 'name');
    
    // If client, they only see themselves
    ClientScopeService::applyScope($query, $request, 'id');

    $clients = $query->orderBy('id', 'desc')
        ->get()
        ->map(function ($client) {
            try {
                $client->name = CryptService::decryptData($client->name) ?? $client->name;
            } catch (\Exception $e) {}
            return $client;
        });

    return response()->json([
        'status' => true,
        'message' => 'clients fetched successfully',
        'data' => $clients
    ]);
}





}
