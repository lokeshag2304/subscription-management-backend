<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Services\CryptService;
use App\Services\ClientScopeService;

class DropdownsController extends Controller
{

public function getDomains(Request $request)
{
    $domains = DB::table('domain_master')
        ->select('id', 'domain_name')
        ->orderBy('id', 'desc')
        ->get()
        ->map(function ($domain) {
            return [
                'id' => $domain->id,
                'name' => $domain->domain_name,
                'domain_name' => $domain->domain_name,
                'value' => $domain->id,
                'label' => $domain->domain_name
            ];
        });

    return Response::json([
        'status' => true,
        'success' => true,
        'message' => 'Domains fetched successfully',
        'data' => $domains
    ]);
}

public function getProducts(Request $request)
{
    $products = DB::table('products')
        ->select('id', 'name')
        ->orderBy('id', 'desc')
        ->get()
        ->map(function ($product) {
            try {
                $name = CryptService::decryptData($product->name) ?? $product->name;
            } catch (\Exception $e) {
                $name = $product->name;
            }
            return [
                'id' => $product->id,
                'name' => $name,
                'value' => $product->id,
                'label' => $name
            ];
        });

    return Response::json([
        'status' => true,
        'success' => true,
        'message' => 'products fetched successfully',
        'data' => $products
    ]);
}

public function getVendors(Request $request)
{
    $vendors = DB::table('vendors')
        ->select('id', 'name')
        ->orderBy('id', 'desc')
        ->get()
        ->map(function ($vendor) {
            try {
                $name = CryptService::decryptData($vendor->name) ?? $vendor->name;
            } catch (\Exception $e) {
                $name = $vendor->name;
            }
            return [
                'id' => $vendor->id,
                'name' => $name,
                'value' => $vendor->id,
                'label' => $name
            ];
        });

    return Response::json([
        'status' => true,
        'success' => true,
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
                $name = CryptService::decryptData($client->name) ?? $client->name;
            } catch (\Exception $e) {
                $name = $client->name;
            }
            return [
                'id' => $client->id,
                'name' => $name,
                'value' => $client->id,
                'label' => $name
            ];
        });

    return Response::json([
        'status' => true,
        'success' => true,
        'message' => 'clients fetched successfully',
        'data' => $clients
    ]);
}




public function addMasterDomain(Request $request)
{
    $name = trim($request->input('domain_name') ?? $request->input('name') ?? '');
    if (!$name) return Response::json(['success' => false, 'message' => 'Domain Name required'], 400);

    // Check if exists in master list
    if (DB::table('domain_master')->where('domain_name', $name)->exists()) {
        return Response::json([
            'success' => false,
            'message' => 'Domain already exists in master list'
        ], 409);
    }

    $now = Carbon::now();
    $id = DB::table('domain_master')->insertGetId([
        'domain_name' => $name,
        'name' => $name, // keep legacy field in sync if needed
        'created_at' => $now,
        'updated_at' => $now
    ]);

    return Response::json([
        'status' => true,
        'success' => true,
        'message' => 'Domain added to master list',
        'data' => [
            'id' => $id,
            'name' => $name,
            'value' => $id,
            'label' => $name
        ]
    ]);
}

}
