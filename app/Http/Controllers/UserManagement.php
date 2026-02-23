<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSinteg;
use App\Lib\Whatsappinteg;
use App\Lib\EmailInteg;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;
use App\Services\AgentPermission;


class UserManagement extends Controller
{

public function list(Request $request)
{
    $page        = $request->input('page', 0);
    $rowsPerPage = $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc');
    $orderBy     = $request->input('orderBy', 'id');
    $search      = strtolower($request->input('search', ''));
    $type        = $request->input('type', 2);

    $loginType = ($type == 1) ? 3 : 2; 

    $query = DB::table('superadmins')
        ->select(
            'id',
            'name',
            'email',
            'address',
            'number',
            'status',
            'd_password',
            'profile',
            'country',
            'created_at'
        )
        ->where('login_type', $loginType);

    $allUsers = $query->get()->map(function ($item) {

        $item->name   = CryptService::decryptData($item->name);
        $item->email  = CryptService::decryptData($item->email);
        $item->number = CryptService::decryptData($item->number);
        $item->address = CryptService::decryptData($item->address);
        $item->d_password = CryptService::decryptData($item->d_password);

        $item->country = $item->country ? CryptService::decryptData($item->country) : null;
        $item->created_at = Carbon::parse($item->created_at)->format('M-d-Y h:ia');

        return $item;
    });

    if ($search !== '') {
        $allUsers = $allUsers->filter(function ($item) use ($search) {
            return str_contains(strtolower($item->name), $search)
                || str_contains(strtolower($item->email), $search)
                || str_contains(strtolower($item->number), $search)
                || ($item->country && str_contains(strtolower($item->country), $search));
        })->values();
    }

    $allUsers = $allUsers->sortBy(function ($item) use ($orderBy) {
        return strtolower((string)($item->{$orderBy} ?? ''));
    }, SORT_REGULAR, $order === 'desc')->values();

    $total = $allUsers->count();

    $usersPage = $allUsers->slice($page * $rowsPerPage, $rowsPerPage)->values();

    return response()->json([
        'rows'  => $usersPage,
        'total' => $total
    ]);
}

    
public function AddUsermanagement(Request $request)
{
    try {

        // 1. Validation (basic)
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email',
            'phone'    => 'required|string|max:20',
            'address'  => 'nullable|string|max:500',
            's_id'     => 'required|integer',
            'password' => 'required|string|min:6',
            'type'     => 'required|in:1,2', // 1=Client, 2=User
            'profile'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // Client ke liye domain_ids required
        if ((int)$request->type === 1 && !is_array($request->domain_ids)) {
            return response()->json([
                'status' => false,
                'message' => 'domain_ids are required for client'
            ], 422);
        }

        // 2. Encrypt email & phone
        $encEmail = CryptService::encryptData($request->email);
        $encPhone = CryptService::encryptData($request->phone);

        if (DB::table('superadmins')->where('email', $encEmail)->exists()) {
            return response()->json(['status' => false, 'message' => 'Email already exists']);
        }

        if (DB::table('superadmins')->where('number', $encPhone)->exists()) {
            return response()->json(['status' => false, 'message' => 'Phone already exists']);
        }

        // 3. Profile upload
        $profilePath = 'admin/logo/dummy.jpeg';

        if ($request->hasFile('profile')) {
            $imageName = time() . '.' . $request->profile->extension();
            $request->profile->move(public_path('admin/usermanagment'), $imageName);
            $profilePath = "admin/usermanagment/{$imageName}";
        }

        // 4. Login type decide
        $loginType = ((int)$request->type === 1) ? 3 : 2; // 3=Client, 2=User

        // 5. Domain JSON only for client
        $domainIdsJson = null;
        if ($loginType === 3) {
            $domainIdsJson = json_encode($request->domain_ids);
        }

        // 6. Insert user
        $userId = DB::table('superadmins')->insertGetId([
            'name'       => CryptService::encryptData($request->name),
            'email'      => $encEmail,
            'number'     => $encPhone,
            'address'    => CryptService::encryptData($request->address),
            'password'   => Hash::make($request->password),
            'd_password' => CryptService::encryptData($request->password),
            'domain_id'  => $domainIdsJson,
            'profile'    => $profilePath,
            'login_type' => $loginType,
            'status'     => 1,
            'added_by'   => $request->s_id,
            'created_at' => now()
        ]);

        // 7. Activity message
        $activityMessage = "";

        if ($loginType === 3) {
            // Client

            $domains = DB::table('domain')
                ->whereIn('id', $request->domain_ids)
                ->get();

            $domainNames = [];

            foreach ($domains as $d) {
                try {
                    $domainNames[] = CryptService::decryptData($d->name);
                } catch (\Exception $e) {
                    $domainNames[] = $d->name;
                }
            }

            $domainListStr = implode(',', $domainNames);

            $activityMessage = "Client added: {$request->name}, Domains: {$domainListStr}";

            $activityDetails = [
                'user_id' => $userId,
                'domains' => $domainNames
            ];

        } else {
            // User
            $activityMessage = "User added: {$request->name}";

            $activityDetails = [
                'user_id' => $userId
            ];
        }

        // 8. Activity log
        DB::table('activities')->insert([
            'action'    => CryptService::encryptData("User Added"),
            's_action'  => CustomCipherService::encryptData("User Added"),
            'user_id'   => $request->s_id,
            'message'   => CryptService::encryptData($activityMessage),
            's_message' => CustomCipherService::encryptData($activityMessage),
            'details'   => CryptService::encryptData(json_encode($activityDetails)),
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => ($loginType === 3 ? 'Client' : 'User') . ' added successfully',
            'user_id' => $userId
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}



public function updateUsermanagement(Request $request)
{
    try {

        // 1. Validation
        $validator = Validator::make($request->all(), [
            'id'      => 'required|integer',
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'phone'   => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
            's_id'    => 'required|integer',
            'type'    => 'required|in:1,2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = DB::table('superadmins')->where('id', $request->id)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Client ke liye domain_ids required
        if ((int)$request->type === 1 && !is_array($request->domain_ids)) {
            return response()->json([
                'status' => false,
                'message' => 'domain_ids are required for client'
            ], 422);
        }

        // ===============================
        // ✅ Duplicate Email / Phone Check
        // ===============================

        $encNewEmail = CryptService::encryptData($request->email);
        $encNewPhone = CryptService::encryptData($request->phone);

        $emailExists = DB::table('superadmins')
            ->where('email', $encNewEmail)
            ->where('id', '!=', $request->id)
            ->exists();

        if ($emailExists) {
            return response()->json([
                'status' => false,
                'message' => 'Email already exists'
            ], 409);
        }

        $phoneExists = DB::table('superadmins')
            ->where('number', $encNewPhone)
            ->where('id', '!=', $request->id)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'status' => false,
                'message' => 'Phone number already exists'
            ], 409);
        }

        // ===============================
        // 2. Decrypt old values
        // ===============================

        $oldName    = CryptService::decryptData($user->name);
        $oldEmail   = CryptService::decryptData($user->email);
        $oldPhone   = CryptService::decryptData($user->number);
        $oldAddress = $user->address ? CryptService::decryptData($user->address) : '';

        $oldDomains = [];
        if ($user->domain_id) {
            $oldDomainIds = json_decode($user->domain_id, true) ?? [];
            $oldDomainsDB = DB::table('domain')->whereIn('id', $oldDomainIds)->get();
            foreach ($oldDomainsDB as $d) {
                $oldDomains[] = CryptService::decryptData($d->name);
            }
        }

        // ===============================
        // 3. Prepare new values
        // ===============================

        $newName    = $request->name;
        $newEmail   = $request->email;
        $newPhone   = $request->phone;
        $newAddress = $request->address ?? '';

        $loginType = ((int)$request->type === 1) ? 3 : 2;

        $newDomainJson = null;
        $newDomains = [];

        if ($loginType === 3) {
            $newDomainJson = json_encode($request->domain_ids);

            $domains = DB::table('domain')->whereIn('id', $request->domain_ids)->get();
            foreach ($domains as $d) {
                $newDomains[] = CryptService::decryptData($d->name);
            }
        }

        // ===============================
        // 4. Detect Changes
        // ===============================

        $changes = [];

        if ($oldName !== $newName) {
            $changes[] = "Name: OLD -> {$oldName} , NEW -> {$newName}";
        }

        if ($oldEmail !== $newEmail) {
            $changes[] = "Email: OLD -> {$oldEmail} , NEW -> {$newEmail}";
        }

        if ($oldPhone !== $newPhone) {
            $changes[] = "Phone: OLD -> {$oldPhone} , NEW -> {$newPhone}";
        }

        if ($oldAddress !== $newAddress) {
            $changes[] = "Address: OLD -> {$oldAddress} , NEW -> {$newAddress}";
        }

        if ($loginType === 3) {
            $oldDomainStr = implode(',', $oldDomains);
            $newDomainStr = implode(',', $newDomains);

            if ($oldDomainStr !== $newDomainStr) {
                $changes[] = "Domains: OLD -> {$oldDomainStr} , NEW -> {$newDomainStr}";
            }
        }

        // ===============================
        // 5. Update DB
        // ===============================

       $updateData = [
            'name'       => CryptService::encryptData($newName),
            'email'      => $encNewEmail,
            'number'     => $encNewPhone,
            'address'    => CryptService::encryptData($newAddress),
            'domain_id'  => $newDomainJson,
            'login_type' => $loginType,
            'updated_at' => now()
        ];

        // 🔐 PASSWORD UPDATE (ONLY IF SENT)
        if ($request->filled('password')) {
            $updateData['password']   = Hash::make($request->password);                 // HASH
            $updateData['d_password'] = CryptService::encryptData($request->password);  // ENCRYPT

            $changes[] = "Password updated";
        }
     DB::table('superadmins')
    ->where('id', $request->id)
    ->update($updateData);


        // ===============================
        // 6. Activity Log
        // ===============================

        if (!empty($changes)) {

            $finalMessage = implode(' | ', $changes);

            DB::table('activities')->insert([
                'action'    => CryptService::encryptData("User Updated"),
                's_action'  => CustomCipherService::encryptData("User Updated"),
                'user_id'   => $request->s_id,
                'message'   => CryptService::encryptData($finalMessage),
                's_message' => CustomCipherService::encryptData($finalMessage),
                'details'   => CryptService::encryptData(json_encode([
                    'user_id' => $request->id,
                    'changes' => $changes
                ])),
                'created_at'=> now(),
                'updated_at'=> now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully'
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
 

public function getUsermanagementDetails(Request $request)
{
    try {

        $id = $request->input('id');

        if (!$id) {
            return response()->json([
                'status' => false,
                'message' => 'id is required'
            ], 422);
        }

        $user = DB::table('superadmins')->where('id', $id)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Decrypt basic fields
        $data = [
            'id'        => $user->id,
            'name'      => CryptService::decryptData($user->name),
            'email'     => CryptService::decryptData($user->email),
            'phone'     => CryptService::decryptData($user->number),
            'password'     => CryptService::decryptData($user->d_password),
            'address'   => $user->address ? CryptService::decryptData($user->address) : '',
            'profile'   => $user->profile,
            'login_type'=> $user->login_type, // 2=user, 3=client
            'type'      => ($user->login_type == 3 ? 1 : 2), // form ke liye (1=client,2=user)
        ];

        // If client → fetch domains
        if ($user->login_type == 3 && !empty($user->domain_id)) {

            $domainIds = json_decode($user->domain_id, true) ?? [];

            $domains = DB::table('domain')
                ->whereIn('id', $domainIds)
                ->get();

            $domainList = [];

            foreach ($domains as $d) {
                $domainList[] = [
                    'id' => $d->id,
                    'name' => CryptService::decryptData($d->name)
                ];
            }

            $data['domain_ids'] = $domainIds;
            $data['domains'] = $domainList;

        } else {
            $data['domain_ids'] = [];
            $data['domains'] = [];
        }

        return response()->json([
            'status' => true,
            'message' => 'User details fetched successfully',
            'data' => $data
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


public function changeActiveDeactiveMultiple(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'id' => 'required|array|min:1',
            'id.*' => 'integer|exists:superadmins,id',
            'status' => 'required|in:0,1',
            'added_by' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 200);
        }

        $subAdmins = DB::table('superadmins')
            ->whereIn('id', $request->id)
            ->get();

        if ($subAdmins->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No matching users found.',
            ], 404);
        }

        DB::table('superadmins')
            ->whereIn('id', $request->id)
            ->update([
                'status' => $request->status,
                'updated_at' => Carbon::now(),
            ]);

        $statusText = $request->status == 1 ? 'Activated' : 'Deactivated';

        foreach ($subAdmins as $user) {
            $decryptedName = CryptService::decryptData($user->name);
            $decryptedEmail = CryptService::decryptData($user->email);
            $loginType = $user->login_type;

            $typeLabel = $loginType == 2 ? 'Agent' : ($loginType == 3 ? 'Customer' : 'User');
            $action = "$statusText $typeLabel";
            $message = "$statusText $typeLabel with email: $decryptedEmail";

            DB::table('activities')->insert([
                'action' => CryptService::encryptData($action),
                's_action' => CustomCipherService::encryptData($action),

                'user_id' => $request->added_by,
                'message' => CryptService::encryptData($message),
                's_message' => CustomCipherService::encryptData($message),

                'details' => CryptService::encryptData(json_encode([
                    'superadmin_id' => $user->id,
                    'status' => $request->status,
                ])),
                'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => "{$typeLabel}s $statusText successfully",
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Something went wrong: ' . $e->getMessage(),
        ], 500);
    }
}

public function deleteUsers(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $superadminIds = $data['ids'] ?? [];
    $deletedBy     = $data['s_id'] ?? null;

    if (empty($superadminIds) || !$deletedBy || !is_array($superadminIds)) {
        return response()->json([
            'success' => false,
            'message' => 'ids (array) and s_id are required',
        ], 422);
    }

    $superadmins = DB::table('superadmins')
        ->whereIn('id', $superadminIds)
        ->get();

    if ($superadmins->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No Users found',
        ], 404);
    }

 
    $superadminNames = [];

    foreach ($superadmins as $admin) {
        try {
            $superadminNames[] = CryptService::decryptData($admin->name);
        } catch (\Exception $e) {
            $superadminNames[] = $admin->name;
        }
    }

    $namesString = implode(', ', $superadminNames);

   
    DB::table('superadmins')
        ->whereIn('id', $superadminIds)
        ->delete();

  
    $activityMessage = "Users deleted: " . $namesString;

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Users Deleted"),
        's_action'   => CustomCipherService::encryptData("Users Deleted"),
        'user_id'    => $deletedBy,
        'message'    => CryptService::encryptData($activityMessage),
        's_message'  => CustomCipherService::encryptData($activityMessage),
        'details'    => CryptService::encryptData(json_encode([
            'superadmin_ids' => $superadminIds,
            'names'          => $superadminNames,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Users deleted successfully.',
        'deleted_Users' => $superadminNames
    ]);
}

public function GetClientDetails(Request $request)
{
    try {

        $DD = json_decode($request->getContent(), true);

        /* =========================
           CLIENT ID
        ========================= */
        $clientId = $DD['id'] ?? null;

        if (!$clientId) {
            return response()->json([
                'status' => false,
                'message' => 'Client id is required'
            ], 400);
        }

        /* =========================
           FETCH CLIENT
        ========================= */
        $client = DB::table('superadmins')
            ->where('id', $clientId)
            ->where('login_type', 3)
            ->first();

        if (!$client) {
            return response()->json([
                'status' => false,
                'message' => 'Client not found'
            ], 404);
        }

        /* =========================
           DOMAIN IDS
        ========================= */
        $clientDomainIds = array_map(
            'intval',
            json_decode($client->domain_id, true) ?? []
        );

        /* =========================
           TYPE & STATUS MAP
        ========================= */
        $typeMap = [
            1 => 'Subscriptions',
            2 => 'SSL',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];

        $statusMap = [
            1 => 'Active',
            2 => 'Deactive'
        ];

        /* =========================
           DASHBOARD COUNTS
        ========================= */
        $typeCounts = [];

        foreach ($typeMap as $typeKey => $typeName) {

            $count = DB::table('categories')
                ->where('record_type', $typeKey)
                ->where(function ($q) use ($typeKey, $clientDomainIds, $clientId) {

                    // Subscriptions & Counter → client_id
                    if (in_array($typeKey, [1, 6])) {
                        $q->where('client_id', $clientId);
                    }
                    // Others → domain_id
                    else {
                        $q->whereIn('domain_id', $clientDomainIds);
                    }
                })
                ->count();

            $typeCounts[] = [
                'type_id'   => $typeKey,
                'type_name' => $typeName,
                'count'     => $count
            ];
        }

        /* =========================
           PAGINATION
        ========================= */
        $page        = (int) ($DD['page'] ?? 0);
        $rowsPerPage = (int) ($DD['rowsPerPage'] ?? 10);
        $orderBy     = $DD['orderBy'] ?? 'id';
        $orderDir    = $DD['orderDir'] ?? 'desc';

        $offset = $page * $rowsPerPage;
        $today  = Carbon::today();

        /* =========================
           FETCH CATEGORIES (PROPER FILTER)
        ========================= */
        $catQuery = DB::table('categories')
            ->where(function ($q) use ($clientDomainIds, $clientId) {

                $q->where(function ($q1) use ($clientDomainIds) {
                    $q1->whereNotIn('record_type', [1, 6])
                       ->whereIn('domain_id', $clientDomainIds);
                })
                ->orWhere(function ($q2) use ($clientId) {
                    $q2->whereIn('record_type', [1, 6])
                       ->where('client_id', $clientId);
                });
            });

        $totalCategories = (clone $catQuery)->count();

        $rows = $catQuery
            ->orderBy($orderBy, $orderDir)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        /* =========================
           RECENT CATEGORIES
        ========================= */
        $recentCategories = [];

        foreach ($rows as $row) {

            $daysToExpired = null;
            if (!empty($row->expiry_date)) {
                $expiry = Carbon::parse($row->expiry_date);
                $daysToExpired = $expiry->gte($today)
                    ? $today->diffInDays($expiry)
                    : -$expiry->diffInDays($today);
            }

            $productName = null;
            if (!empty($row->product_id)) {
                try {
                    $productId = CryptService::decryptData($row->product_id);
                } catch (\Exception $e) {
                    $productId = $row->product_id;
                }

                $product = DB::table('products')->where('id', $productId)->first();
                if ($product && $product->name) {
                    try {
                        $productName = CryptService::decryptData($product->name);
                    } catch (\Exception $e) {
                        $productName = $product->name;
                    }
                }
            }

            $recentCategories[] = [
                'id'              => $row->id,
                'record_type'     => $typeMap[$row->record_type] ?? 'Unknown',
                'status'          => $statusMap[$row->status] ?? 'Unknown',
                'product_name'    => $productName,
                'created_at'      => Carbon::parse($row->created_at)->format('d F Y'),
                'days_to_expired' => $daysToExpired
            ];
        }

        /* =========================
           FINAL RESPONSE
        ========================= */
        return response()->json([
            'status' => true,
            'type_counts' => $typeCounts,
            'recent_categories' => [
                'total'       => $totalCategories,
                'page'        => $page,
                'rowsPerPage' => $rowsPerPage,
                'data'        => $recentCategories
            ]
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => 'Client details fetch failed',
            'error' => $e->getMessage()
        ], 500);
    }
}




}
