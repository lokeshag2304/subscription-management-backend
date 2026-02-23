<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

class DropdownsController extends Controller
{




public function getDomains(Request $request)
{
    $domains = DB::table('domain')
        ->select('id', 'name')
        ->orderBy('id','desc')
        ->get()
        ->map(function ($domain) {
            try {
                $domain->name = CryptService::decryptData($domain->name);
            } catch (\Exception $e) {
                $domain->name = $domain->name;
            }
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
    $domains = DB::table('products')
        ->select('id', 'name')
        ->orderBy('id','desc')
        ->get()
        ->map(function ($domain) {
            try {
                $domain->name = CryptService::decryptData($domain->name);
            } catch (\Exception $e) {
                $domain->name = $domain->name;
            }
            return $domain;
        });

    return response()->json([
        'status' => true,
        'message' => 'products fetched successfully',
        'data' => $domains
    ]);
}

public function getVendors(Request $request)
{
    $domains = DB::table('vendors')
        ->select('id', 'name')
        ->orderBy('id','desc')
        ->get()
        ->map(function ($domain) {
            try {
                $domain->name = CryptService::decryptData($domain->name);
            } catch (\Exception $e) {
                $domain->name = $domain->name;
            }
            return $domain;
        });

    return response()->json([
        'status' => true,
        'message' => 'vendors fetched successfully',
        'data' => $domains
    ]);
}

public function getClients(Request $request)
{
    $domains = DB::table('superadmins')
        ->select('id', 'name')
        ->where('login_type', 3)   
        ->orderBy('id', 'desc')
        ->get()
        ->map(function ($domain) {
            try {
                $domain->name = CryptService::decryptData($domain->name);
            } catch (\Exception $e) {
                $domain->name = $domain->name;
            }
            return $domain;
        });

    return response()->json([
        'status' => true,
        'message' => 'clients fetched successfully',
        'data' => $domains
    ]);
}





}
