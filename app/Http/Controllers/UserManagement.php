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
use App\Models\Superadmin;
use App\Services\DateFormatterService;
use App\Services\ActivityLogger;
use App\Models\ImportHistory;



use App\Services\AuditFileService;

class UserManagement extends Controller
{
    use \App\Traits\DataNormalizer;
 
    private function logActivity($action, $record, $oldData = null, $newData = null, $message = null)
    {
        try {
            $user = auth()->user() ?: (object)['id' => request()->input('auth_user_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];
            $loginType = $record->login_type;
            
            $decrypt = function($val) {
                if (!$val || !is_string($val)) return $val;
                try { return CryptService::decryptData($val) ?? $val; } catch (\Exception $e) { return $val; }
            };

            $standardize = function($data) use ($record, $decrypt) {
                if (!$data) return $data;
                $arr = is_array($data) ? $data : (method_exists($data, 'toArray') ? $data->toArray() : (array)$data);
                
                $result = [];
                // Target User ka naam hamesha snapshots mein include karo
                $result['Target User'] = $decrypt($record->name);
                
                if (isset($arr['name']))    $result['Name']    = $decrypt($arr['name']);
                if (isset($arr['email']))   $result['Email']   = $decrypt($arr['email']);
                if (isset($arr['number']))  $result['Phone']   = $decrypt($arr['number']);
                if (isset($arr['address'])) $result['Address'] = $decrypt($arr['address']);
                if (isset($arr['country'])) $result['Country'] = $decrypt($arr['country']);
                if (isset($arr['status']))  $result['Status']  = $arr['status'] == 1 ? 'Active' : 'Inactive';
                
                return $result;
            };
 
            $moduleLabel = ($loginType == 3 ? 'Client' : ($loginType == 1 ? 'SuperAdmin' : 'User')) . ": " . $decrypt($record->name);

            ActivityLogger::logActivity(
                $user, 
                strtoupper($action), 
                $moduleLabel,
                'superadmins',
                $record->id,
                $standardize($oldData),
                $standardize($newData),
                $message,
                request()
            );
        } catch (\Exception $e) {}
    }


public function list(Request $request)
{
    $perPage = (int)$request->input('rowsPerPage', $request->input('limit', 10));
    if ($perPage < 1) $perPage = 10;

    $page = $request->input('page');
    if ($page !== null) {
        $offset = (int)$page * $perPage;
    } else {
        $offset = (int)$request->input('offset', 0);
    }
    if ($offset < 0) $offset = 0;

    $order       = $request->input('order', 'desc');
    $orderBy     = $request->input('orderBy', 'id');
    $search      = strtolower($request->input('search', ''));
    $type        = $request->input('type', 2);
    $authLoginType = $request->input('auth_login_type');

    if ($type == 3 && $authLoginType != 1) {
        return response()->json([
            'status' => false,
            'message' => 'Unauthorized access. Only SuperAdmins can view SuperAdmin accounts.'
        ], 403);
    }

    if ($type == 1) {
        $loginType = 3; // Client
    } elseif ($type == 3) {
        $loginType = 1; // Superadmin
    } else {
        $loginType = 2; // User
    }

    $query = DB::table('superadmins')
        ->select(
            'id',
            'name',
            'email',
            'address',
            'number',
            'phone_number',
            'dial_code',
            'country_code',
            'status',
            'profile',
            'country',
            'otp_enabled',
            'created_at'
        )
        ->where('login_type', $loginType);

    $allUsers = $query->get()->map(function ($item) {

        $safeDecrypt = function($value) {
            if (empty($value)) return $value;
            try {
                return CryptService::decryptData($value);
            } catch (\Exception $e) {
                return $value;
            }
        };

        $item->name       = $safeDecrypt($item->name);
        $item->email      = $safeDecrypt($item->email);
        $item->number     = $safeDecrypt($item->number);
        $item->address    = $safeDecrypt($item->address);
        // d_password intentionally omitted – security risk

        $item->country = $item->country ? $safeDecrypt($item->country) : null;
        $item->created_at = Carbon::parse($item->created_at)->format('j/n/Y, g:i:s a');

        // Fallback for phone display
        if (empty($item->phone_number) && !empty($item->number)) {
            $item->phone_number = $item->number;
        }

        return $item;
    });

    if ($search !== '') {
        $allUsers = $allUsers->filter(function ($item) use ($search) {
            return str_contains(strtolower($item->name), $search)
                || str_contains(strtolower($item->email), $search)
                || str_contains(strtolower($item->number), $search)
                || ($item->phone_number && str_contains(strtolower($item->phone_number), $search))
                || ($item->country && str_contains(strtolower($item->country), $search));
        })->values();
    }

    $allUsers = $allUsers->sortBy(function ($item) use ($orderBy) {
        return strtolower((string)($item->{$orderBy} ?? ''));
    }, SORT_REGULAR, $order === 'desc')->values();

    // ... after filtering and sorting ...
    $total = $allUsers->count();
    $usersPage = $allUsers->slice($offset, $perPage)->values();

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
            'country'  => 'nullable|string|max:100',
            'address'  => 'nullable|string|max:500',
            's_id'     => 'required|integer',
            'password' => 'required|string|min:6',
            'type'     => 'required|in:1,2,3', // 1=Client, 2=User, 3=SuperAdmin
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

        $authLoginType = $request->input('auth_login_type');
        if ((int)$request->type === 3 && $authLoginType != 1) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access. Only SuperAdmins can add SuperAdmin accounts.'
            ], 403);
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

        if ((int)$request->type === 1) {
            $loginType = 3; // Client
        } elseif ((int)$request->type === 3) {
            $loginType = 1; // SuperAdmin
        } else {
            $loginType = 2; // User
        }

        // 5. Domain JSON only for client
        $domainIdsJson = null;
        if ($loginType === 3) {
            $domainIdsJson = json_encode($request->domain_ids);
        }

        // 6. Insert user using Eloquent
        $record = Superadmin::create([
            'name'         => CryptService::encryptData(self::normalizeData($request->name, 'Name')),
            'email'        => $encEmail,
            'number'       => $encPhone, // Keep for backward compatibility
            'phone_number' => $request->phone,
            'dial_code'    => $request->dial_code,
            'country_code' => $request->country_code,
            'address'      => CryptService::encryptData($request->address),
            'password'     => Hash::make($request->password),
            'domain_id'    => $domainIdsJson,
            'profile'      => $profilePath,
            'country'      => CryptService::encryptData($request->country ?? 'India'),
            'login_type'   => $loginType,
            'otp_enabled'  => $request->input('otp_enabled', 1),
            'status'       => 1,
            'added_by'     => $request->s_id,
        ]);

        $userId = $record->id;
        
        $itemData = [
            'id'           => $record->id,
            'name'         => $request->name,
            'email'        => $request->email,
            'number'       => $request->phone,
            'phone'        => $request->phone,
            'phone_number' => $record->phone_number,
            'dial_code'    => $record->dial_code,
            'country_code' => $record->country_code,
            'address'      => $request->address,
            'status'       => $record->status,
            'profile'      => $record->profile,
            'otp_enabled'  => $record->otp_enabled,
            'created_at'   => DateFormatterService::format($record->created_at),
        ];

        if ($loginType === 3) {
            $itemData['domain_ids'] = $request->domain_ids;
            // Fetch names for immediate UI display
            $domainNames = DB::table('domains')->whereIn('id', $request->domain_ids ?? [])->pluck('name');
            $itemData['domain_names'] = $domainNames->map(fn($d) => \App\Services\CryptService::decryptData($d))->toArray();
        }

        // 7. Activity message
        $activityMessage = "";

        if ($loginType === 3) {
            // Client

            $domains = DB::table('domains')
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
        $this->logActivity('CREATE', $record, null, $record, $activityMessage);

        return response()->json([
            'status' => true,
            'message' => ($loginType === 3 ? 'Client' : 'User') . ' added successfully',
            'user_id' => $userId,
            'data'    => $itemData
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
            'country' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            's_id'    => 'required|integer',
            'type'    => 'required|in:1,2,3',
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

        $authLoginType = $request->input('auth_login_type');
        if ((int)$request->type === 3 && $authLoginType != 1) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access. Only SuperAdmins can update SuperAdmin accounts.'
            ], 403);
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

        try {
            $oldName = CryptService::decryptData($user->name);
        } catch (\Exception $e) {
            $oldName = $user->name;
        }

        try {
            $oldEmail = CryptService::decryptData($user->email);
        } catch (\Exception $e) {
            $oldEmail = $user->email;
        }

        try {
            $oldPhone = CryptService::decryptData($user->number);
        } catch (\Exception $e) {
            $oldPhone = $user->number;
        }

        try {
            $oldAddress = $user->address ? CryptService::decryptData($user->address) : '';
        } catch (\Exception $e) {
            $oldAddress = $user->address ?? '';
        }

        try {
            $oldCountry = $user->country ? CryptService::decryptData($user->country) : 'India';
        } catch (\Exception $e) {
            $oldCountry = $user->country ?? 'India';
        }

        $oldDomains = [];
        if ($user->domain_id) {
            $oldDomainIds = json_decode($user->domain_id, true) ?? [];
            $oldDomainsDB = DB::table('domains')->whereIn('id', $oldDomainIds)->get();
            foreach ($oldDomainsDB as $d) {
                try {
                    $oldDomains[] = CryptService::decryptData($d->name);
                } catch (\Exception $e) {
                    $oldDomains[] = $d->name;
                }
            }
        }

        // ===============================
        // 3. Prepare new values
        // ===============================

        $newName    = self::normalizeData($request->name, 'Name');
        $newEmail   = $request->email;
        $newPhone   = $request->phone;
        $newCountry = $request->country ?? 'India';
        $newAddress = self::normalizeData($request->address ?? '', 'Address');


        if ((int)$request->type === 1) {
            $loginType = 3; // Client
        } elseif ((int)$request->type === 3) {
            $loginType = 1; // SuperAdmin
        } else {
            $loginType = 2; // User
        }

        $newDomainJson = null;
        $newDomains = [];

        if ($loginType === 3) {
            // Ensure domain_ids is handled as a clean array
            $domainIds = is_array($request->domain_ids) ? $request->domain_ids : [];
            $newDomainJson = json_encode($domainIds);

            if (!empty($domainIds)) {
                $domains = DB::table('domains')->whereIn('id', $domainIds)->get();
                foreach ($domains as $d) {
                    try {
                        $newDomains[] = CryptService::decryptData($d->name);
                    } catch (\Exception $e) {
                        $newDomains[] = $d->name;
                    }
                }
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

        if ($oldCountry !== $newCountry) {
            $changes[] = "Country: OLD -> {$oldCountry} , NEW -> {$newCountry}";
        }

        if ($loginType === 3) {
            sort($oldDomains);
            sort($newDomains);
            $oldDomainStr = implode(', ', $oldDomains);
            $newDomainStr = implode(', ', $newDomains);

            if ($oldDomainStr !== $newDomainStr) {
                $changes[] = "Domains: OLD -> [{$oldDomainStr}] , NEW -> [{$newDomainStr}]";
            }
        }

        if ((int)$user->otp_enabled !== (int)$request->otp_enabled) {
            $oldOtp = (int)$user->otp_enabled === 1 ? 'Enabled' : 'Disabled';
            $newOtp = (int)$request->otp_enabled === 1 ? 'Enabled' : 'Disabled';
            $changes[] = "OTP: OLD -> {$oldOtp} , NEW -> {$newOtp}";
        }

        // ===============================
        // 5. Update DB
        // ===============================

        // ===============================
        // 5a. Handle Profile Image Upload
        // ===============================

        $profilePath = $user->profile; // Keep existing profile by default

        if ($request->input('remove_profile') == '1') {
            $profilePath = null;
            if ($user->profile) {
                $changes[] = "Profile image removed";
            }
        }

        if ($request->hasFile('profile')) {
            $imageName  = time() . '.' . $request->profile->extension();
            $request->profile->move(public_path('admin/usermanagment'), $imageName);
            $profilePath = "admin/usermanagment/{$imageName}";
            $changes[] = $user->profile ? "Profile image updated" : "Profile image added";
        }

       $updateData = [
            'name'         => CryptService::encryptData($newName),
            'email'        => $encNewEmail,
            'number'       => $encNewPhone,
            'phone_number' => $request->phone,
            'dial_code'    => $request->dial_code,
            'country_code' => $request->country_code,
            'address'      => CryptService::encryptData($newAddress),
            'domain_id'    => $newDomainJson,
            'country'      => CryptService::encryptData($newCountry),
            'login_type'   => $loginType,
            'otp_enabled'  => $request->input('otp_enabled', 1),
            'profile'      => $profilePath,
        ];

        // 🔐 PASSWORD UPDATE (ONLY IF SENT)
        if ($request->filled('password')) {
            $updateData['password']   = Hash::make($request->password);
            $updateData['d_password'] = $request->password;
        }

        $superadminModel = Superadmin::findOrFail($request->id);
        $superadminModel->update($updateData);

        $itemData = [
            'id'           => $superadminModel->id,
            'name'         => $newName,
            'email'        => $newEmail,
            'number'       => $newPhone,
            'phone'        => $newPhone,
            'phone_number' => $superadminModel->phone_number,
            'dial_code'    => $superadminModel->dial_code,
            'country_code' => $superadminModel->country_code,
            'address'      => $newAddress,
            'status'       => $superadminModel->status,
            'profile'      => $profilePath,                // ✅ Always reflects the updated path
            'otp_enabled'  => $superadminModel->otp_enabled,
            'created_at'   => DateFormatterService::format($superadminModel->created_at),
        ];

        if ($loginType === 3) {
            $itemData['domain_ids']   = $request->domain_ids;
            $itemData['domain_names'] = $newDomains; // ✅ Add this for instant UI update
        }

        $activityMessage = "Updated User: {$newName} ({$newEmail}) | Changes: " . implode(' | ', $changes);
        $this->logActivity('UPDATE', $superadminModel, $user, $superadminModel, $activityMessage);

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data'    => $itemData
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

        $authLoginType = $request->input('auth_login_type');
        if ($user->login_type == 1 && $authLoginType != 1) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access. Only SuperAdmins can view SuperAdmin details.'
            ], 403);
        }

        $safeDecrypt = function($value) {
            if (empty($value)) return $value;
            try {
                return CryptService::decryptData($value);
            } catch (\Exception $e) {
                return $value;
            }
        };

        // Decrypt basic fields safely
        $data = [
            'id'           => $user->id,
            'name'         => $safeDecrypt($user->name),
            'email'        => $safeDecrypt($user->email),
            'phone'        => $user->phone_number ?: $safeDecrypt($user->number),
            'phone_number' => $user->phone_number,
            'dial_code'    => $user->dial_code,
            'country_code' => $user->country_code,
            'country'      => $user->country ? $safeDecrypt($user->country) : 'India',
            'password'     => '', // SECURITY: Never send original password to frontend
            'address'      => $user->address ? $safeDecrypt($user->address) : '',
            'profile'      => $user->profile,
            'login_type'   => $user->login_type, // 2=user, 3=client
            'otp_enabled'  => $user->otp_enabled ?? 1,
            'type'         => ($user->login_type == 3 ? 1 : ($user->login_type == 1 ? 3 : 2)), // form ke liye (1=client,2=user, 3=superadmin)
        ];

        // If client → fetch domains
        if ($user->login_type == 3 && !empty($user->domain_id)) {

            $domainIds = json_decode($user->domain_id, true) ?? [];

            $domains = DB::table('domains')
                ->whereIn('id', $domainIds)
                ->get();

            $domainList = [];

            foreach ($domains as $d) {
                try {
                    $decryptedDomainName = CryptService::decryptData($d->name);
                } catch (\Exception $e) {
                    $decryptedDomainName = $d->name;
                }
                
                $domainList[] = [
                    'id' => $d->id,
                    'name' => $decryptedDomainName
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

            $this->logActivity('UPDATE', $user, $user, ['status' => $request->status], $message);
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

    $authLoginType = $request->input('auth_login_type');
    foreach ($superadmins as $admin) {
        if ($admin->login_type == 1 && $authLoginType != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access. Only SuperAdmins can delete SuperAdmin accounts.'
            ], 403);
        }
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

    \App\Services\ActivityLogger::logActivity(
        auth()->user() ?? (object)['id' => $deletedBy],
        'DELETE',
        'Clients/Users',
        'superadmins',
        null,
        ['deleted_ids' => $superadminIds, 'names' => $superadminNames],
        null,
        $activityMessage,
        $request
    );

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
           TYPE MAP
        ========================= */
        $typeMap = [
            1 => ['table' => 'subscriptions', 'name' => 'Subscriptions'],
            2 => ['table' => 's_s_l_s',       'name' => 'SSL'],
            3 => ['table' => 'hostings',      'name' => 'Hosting'],
            4 => ['table' => 'domains',       'name' => 'Domains'],
            5 => ['table' => 'emails',        'name' => 'Emails'],
            6 => ['table' => 'counters',      'name' => 'Counter']
        ];

        /* =========================
           DASHBOARD COUNTS
        ========================= */
        $typeCounts = [];
        $queries = [];

        foreach ($typeMap as $typeId => $info) {
            
            $qCount = DB::table($info['table'])->where('client_id', $clientId)->count();

            $typeCounts[] = [
                'type_id'   => $typeId,
                'type_name' => $info['name'],
                'count'     => $qCount
            ];

            $q = DB::table($info['table'])
                ->select(
                    'id',
                    DB::raw("$typeId as record_type"),
                    'status',
                    'product_id',
                    'created_at',
                    'days_left as days_to_expired',
                    'grace_period',
                    'due_date'
                )
                ->where('client_id', $clientId);
            
            $queries[] = $q;
        }

        /* =========================
           PAGINATION
        ========================= */
        $page        = (int) ($DD['page'] ?? 0);
        $rowsPerPage = (int) ($DD['rowsPerPage'] ?? 10);
        $orderBy     = $DD['orderBy'] ?? 'created_at';
        $orderDir    = $DD['orderDir'] ?? 'desc';
        if ($orderBy === 'id') $orderBy = 'created_at'; 

        $offset = $page * $rowsPerPage;
        $today  = Carbon::today();

        /* =========================
           FETCH COMBINED SERVICES (UNION)
        ========================= */
        $mainQuery = array_shift($queries);
        foreach ($queries as $q) {
            $mainQuery->unionAll($q);
        }

        $combinedQuery = DB::query()->fromSub($mainQuery, 'combined_results');
        
        $totalCategories = $combinedQuery->count();

        $rows = $combinedQuery
            ->orderBy($orderBy, $orderDir)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        /* =========================
           RECENT CATEGORIES
        ========================= */
        $recentCategories = [];

        foreach ($rows as $row) {

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
                'record_type'     => $typeMap[$row->record_type]['name'] ?? 'Unknown',
                'status'          => (!isset($row->status) || $row->status == 1) ? 'Active' : 'Deactive',
                'product_name'    => $productName,
                'created_at'      => Carbon::parse($row->created_at)->format('d F Y'),
                'days_to_expired' => $row->days_to_expired ?? 0,
                'grace_period'    => $row->grace_period ?? 0,
                'due_date'        => $row->due_date
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




    public function logExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'total_records' => 'required|integer',
            'data_snapshot' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $type = $request->input('type', 2);
        if ($type == 1) $moduleName = 'Clients';
        elseif ($type == 3) $moduleName = 'SuperAdmins';
        else $moduleName = 'Users';

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

        // 2. Log Activity
        ActivityLogger::exported(
            $userId,
            $moduleName,
            $request->total_records,
            $request
        );


        return response()->json([
            'success' => true,
            'message' => 'Export logged successfully',
            'history_id' => $history->id
        ]);
    }

    /**
     * Import user records from CSV/Excel.
     */
    public function import(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
        }

        $data = \App\Services\AuditFileService::parseFile($file);
        if (empty($data)) {
            return response()->json(['success' => false, 'message' => 'File is empty or invalid'], 400);
        }

        $type = (int)$request->input('record_type', $request->input('type', 2)); // 1=Client, 2=User, 3=SuperAdmin
        if ($type === 1) $loginType = 3;
        elseif ($type === 3) $loginType = 1;
        else $loginType = 2;

        $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
        if (!$forceImport) {
            $rowNum = 1;
            $issues = [];
            foreach ($data as $row) {
                $rowNum++;
                $name = trim($row['name'] ?? $row['user name'] ?? $row['client name'] ?? $row['full name'] ?? '');
                $email = strtolower(trim($row['email'] ?? $row['email address'] ?? ''));
                
                $missing = [];
                if (!$name) $missing[] = 'name';
                if (!$email) $missing[] = 'email';
                
                if (!empty($missing)) {
                    $issues[] = ['row' => $rowNum, 'missing_fields' => $missing];
                }
            }

            if (!empty($issues)) {
                $user = auth()->user();
                $moduleName = ($loginType === 3 ? 'Clients' : ($loginType === 1 ? 'SuperAdmins' : 'Users'));
                $history = \App\Models\ImportHistory::create([
                    'module_name' => $moduleName, 
                    'action' => 'IMPORT', 
                    'file_name' => $file->getClientOriginalName(), 
                    'imported_by' => $user->name ?? 'System / Admin', 
                    'successful_rows' => 0, 
                    'failed_rows' => count($issues), 
                    'duplicates_count' => 0,
                    'data_snapshot' => json_encode($issues)
                ]);
                \App\Services\AuditFileService::storeImport($history, $file);

                \App\Services\ActivityLogger::imported($user->id ?? 1, $moduleName, 0, $history->id, count($issues), 0);

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

        // Pre-fetch existing emails and numbers for this login_type to avoid duplicates
        $existing = DB::table('superadmins')
            ->where('login_type', $loginType)
            ->get(['email', 'number'])
            ->map(function($u) {
                return [
                    'email' => strtolower(trim(\App\Services\CryptService::decryptData($u->email) ?? $u->email)),
                    'number' => trim(\App\Services\CryptService::decryptData($u->number) ?? $u->number)
                ];
            });
        
        $emails = $existing->pluck('email')->toArray();
        $numbers = $existing->pluck('number')->toArray();

        foreach ($data as $row) {
            $name = trim($row['name'] ?? $row['user name'] ?? $row['client name'] ?? $row['full name'] ?? '');
            $email = strtolower(trim($row['email'] ?? $row['email address'] ?? ''));
            $number = trim($row['phone'] ?? $row['number'] ?? $row['phone number'] ?? $row['mobile'] ?? '');
            $password = trim($row['password'] ?? '123456');

            if (!$name || !$email) {
                $failed++;
                continue;
            }

            // Duplicate check
            if (in_array($email, $emails) || (!empty($number) && in_array($number, $numbers))) {
                $duplicates++;
                $duplicateRows[] = $row;
                continue;
            }

            try {
                $record = \App\Models\Superadmin::create([
                    'name'       => \App\Services\CryptService::encryptData(self::normalizeData($name, 'Name')),
                    'email'      => \App\Services\CryptService::encryptData($email),
                    'number'     => \App\Services\CryptService::encryptData($number),
                    'password'   => \Illuminate\Support\Facades\Hash::make($password),
                    'login_type' => $loginType,
                    'status'     => 1,
                    'added_by'   => $request->input('s_id', 1),
                ]);
                
                $inserted++;
                $snapshot[] = ['name' => $name, 'email' => $email, 'id' => $record->id];
                $emails[] = $email;
                if (!empty($number)) $numbers[] = $number;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $s_id = $request->input('s_id', 1);
        $moduleName = ($loginType === 3 ? 'Clients' : ($loginType === 1 ? 'SuperAdmins' : 'Users'));
        
        $history = \App\Services\AuditFileService::logImport($s_id, $moduleName, $inserted, $duplicates, $failed, $snapshot);
        \App\Services\AuditFileService::storeImport($history, $file);

        if ($duplicates > 0 && !empty($data)) {
            $headers = array_keys($data[0]);
            \App\Services\AuditFileService::storeDuplicates($history, $headers, $duplicateRows);
        }

        \App\Services\ActivityLogger::imported($s_id, $moduleName, $inserted, $history->id);

        return response()->json([
            'status'     => true,
            'success'    => true,
            'inserted'   => $inserted,
            'duplicates' => $duplicates,
            'failed'     => $failed,
            'history_id' => $history->id,
        ]);
    }
}

