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
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;

class ProfileController extends Controller
{

public function getProfile(Request $request)
{
    try {

        $data = json_decode($request->getContent(), true);
        $s_id = $data['s_id'] ?? null;

        if (!$s_id) {
            return response()->json([
                'success' => false,
                'message' => 's_id is required'
            ], 400);
        }

        $settings = DB::table('superadmins')->where('id', $s_id)->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found'
            ], 404);
        }

        $decryptedData = [];

        foreach ((array) $settings as $key => $value) {
            try {
                $decryptedData[$key] = CryptService::decryptData($value);
            } catch (\Exception $e) {
                $decryptedData[$key] = $value;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $decryptedData
        ], 200);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => 'Something went wrong!',
            'error' => $e->getMessage()
        ], 500);
    }
}



public function updateProfile(Request $request)
{
    /* =========================
       BASIC VALIDATION
    ========================= */
    $request->validate([
        's_id'   => 'required|exists:superadmins,id',
        'name'   => 'required|string|max:255',
        'email'  => 'required|email|max:255',
        'password' => 'nullable|string|min:6',
        'profile'  => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
    ]);

    /* =========================
       FETCH ADMIN
    ========================= */
    $admin = DB::table('superadmins')->where('id', $request->s_id)->first();

    if (!$admin) {
        return response()->json([
            'status'  => false,
            'message' => 'Admin not found'
        ], 404);
    }

    /* =========================
       EMAIL ALREADY EXISTS CHECK
    ========================= */
    $emailExists = DB::table('superadmins')
        ->where('id', '!=', $request->s_id)
        ->where('email', CryptService::encryptData($request->email))
        ->exists();

    if ($emailExists) {
        return response()->json([
            'status'  => false,
            'message' => 'Email already exists'
        ], 422);
    }

    /* =========================
       OLD VALUES
    ========================= */
    $oldName    = $admin->name ? CryptService::decryptData($admin->name) : null;
    $oldEmail   = $admin->email ? CryptService::decryptData($admin->email) : null;
    $oldProfile = $admin->profile;

    /* =========================
       PROFILE IMAGE
    ========================= */
    $profilePath = $oldProfile;

    if ($request->hasFile('profile')) {
        $imageName = time() . '.' . $request->profile->extension();
        $request->profile->move(public_path('admin/profiles'), $imageName);
        $profilePath = "admin/profiles/{$imageName}";

        if ($oldProfile && file_exists(public_path($oldProfile))) {
            unlink(public_path($oldProfile));
        }
    }

    /* =========================
       UPDATE DATA
    ========================= */
    $updateData = [
        'name'  => $oldName !== $request->name
            ? CryptService::encryptData($request->name)
            : $admin->name,

        'email' => $oldEmail !== $request->email
            ? CryptService::encryptData($request->email)
            : $admin->email,

        'profile' => $profilePath,
    ];

    if ($request->filled('password')) {
        $updateData['d_password'] = $request->password;
        $updateData['password']   = Hash::make($request->password);
    }

    DB::table('superadmins')
        ->where('id', $request->s_id)   // ✅ FIXED
        ->update($updateData);

    /* =========================
       RESPONSE
    ========================= */
    return response()->json([
        'status'  => true,
        'message' => 'Profile updated successfully',
        'data'    => [
            'id'      => $admin->id,
            'name'    => $request->name,
            'email'   => $request->email,
            'profile' => $profilePath,
        ]
    ]);
}





    
}
