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



class DomainController extends Controller
{

public function storeDomain(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $domainName = $data['name'] ?? null;
    $s_id       = $data['s_id'] ?? null;

    if (!$domainName || !$s_id) {
        return response()->json([
            'success' => false,
            'message' => 'name and s_id are required',
        ], 422);
    }

    $encryptedName = CryptService::encryptData($domainName);

    $domainId = DB::table('domain')->insertGetId([
        'name' => $encryptedName,
        'created_at' => now(),
    ]);

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Domain Added"),
        's_action'   => CustomCipherService::encryptData("Domain Added"),
        'user_id'    => $s_id,
        'message'    => CryptService::encryptData("Domain added: " . $domainName),
        's_message'  => CustomCipherService::encryptData("Domain added: " . $domainName),
        'details'    => CryptService::encryptData(json_encode([
            'domain_id' => $domainId,
            'name'      => $domainName,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Domain saved successfully.',
        'domain_id' => $domainId
    ]);
}


public function updateDomain(Request $request)
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
    $domain = DB::table('domain')->where('id', $domainId)->first();

    if (!$domain) {
        return response()->json([
            'success' => false,
            'message' => 'Domain not found',
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
    DB::table('domain')
        ->where('id', $domainId)
        ->update([
            'name' => $encryptedNewName
        ]);

    // Prepare activity message
    $changeMessage = "Domain updated: OLD -> {$oldName} , NEW -> {$newName}";

    // Log activity
    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Domain Updated"),
        's_action'   => CustomCipherService::encryptData("Domain Updated"),
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
        'message' => 'Domain updated successfully.',
    ]);
}





public function DomainList(Request $request)
{
    $page        = (int) $request->input('page', 0);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc'); 
    $orderBy     = $request->input('orderBy', 'name'); 
    $search      = strtolower($request->input('search', ''));

    $allDomains = DB::table('domain')
        ->get()
        ->map(function ($item) {

            try {
                $item->name = CryptService::decryptData($item->name);
            } catch (\Exception $e) {}

            $item->created_at = Carbon::parse($item->created_at)->format('M-d-Y h:i A');
            return $item;
        });

    if ($search !== '') {
        $allDomains = $allDomains->filter(function ($item) use ($search) {
            return str_contains(strtolower($item->name), $search);
        })->values();
    }

    $allDomains = $allDomains->sortBy(function ($item) use ($orderBy) {
        return strtolower($item->{$orderBy});
    }, SORT_REGULAR, $order === 'desc')->values();

    $total = $allDomains->count();

    $paged = $allDomains->slice($page * $rowsPerPage, $rowsPerPage)->values();

    return response()->json([
        'rows'  => $paged,
        'total' => $total
    ]);
}





public function deleteDomains(Request $request)
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

    // Fetch domains
    $domains = DB::table('domain')
        ->whereIn('id', $domainIds)
        ->get();

    if ($domains->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No domains found',
        ], 404);
    }

    // Decrypt names
    $domainNames = [];

    foreach ($domains as $domain) {
        try {
            $domainNames[] = CryptService::decryptData($domain->name);
        } catch (\Exception $e) {
            $domainNames[] = $domain->name;
        }
    }

    // Convert to comma separated string
    $domainNamesString = implode(', ', $domainNames);

    // Delete domains
    DB::table('domain')->whereIn('id', $domainIds)->delete();

    // Activity message
    $activityMessage = "Domains deleted: " . $domainNamesString;

    // Insert activity
    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Domain Deleted"),
        's_action'   => CustomCipherService::encryptData("Domain Deleted"),
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
        'message' => 'Domains deleted successfully.',
        'deleted_domains' => $domainNames
    ]);
}



}
