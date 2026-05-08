<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSInteg;
use App\Lib\WhatsappInteg;
use App\Lib\EmailInteg;
use App\Services\CryptService;
use App\Services\CustomCipherService;

use Carbon\Carbon;

class SettingController extends Controller
{

    public function __construct()
    {
        // $this->middleware('cors');
    }

public function getSettings(Request $request)
{
    $subadminId = $request->input('subadmin_id'); 

    $query = DB::table('setting')
        ->select(
            'id',
            'wtsp_api_key',
            'wtsp_pass',
            'zoho_api_key',
            'zoho_from_email',
            'sms_api_key',
            'sms_secret_key',
            'bounce_address',
            'logo',
            'favicon',
            'tenant_id',
            'clinet_id',
            'clinet_secret',
            'wasabi_secret_key',
            'wasabi_api_key',
            'wasabi_region',
            'wasabi_endpoint',
            'wasabi_bucket',
            'notification_tune',
            'last_notification_template',
            'last_notification_minutes',
            'first_notification_template',
            'first_notification_minutes',
            'closure_notification_template',
            'closure_notification_minutes',
            'notification_status',
            'google_project_id',
            'google_bucket',
            'google_client_email',
            'google_private_key',
            'google_private_key_id',
            'google_client_id',
            'aws_api_key',
            'aws_secret_key',
            'aws_region',
            'aws_bucket',
            'storage_type',
            'webhook_google_secret_id',
            'webhook_google_client_id',
            'webhook_type'
        );

    // Agar subadmin_id diya gaya hai to uske hisaab se filter karega
    if ($subadminId) {
        $query->where('subadmin_id', $subadminId);
    }

    $settings = $query->first();

    // if (!$settings) {
    //     return response()->json([
    //         'success' => false,
    //         'message' => 'No settings found'
    //     ], 404);
    // }

    $settingsArray = (array) $settings;

    $decryptFields = [
        'wtsp_api_key',
        'wtsp_pass',
        'zoho_api_key',
        'zoho_from_email',
        'sms_api_key',
        'sms_secret_key',
        'bounce_address',
        'wasabi_secret_key',
        'wasabi_api_key',
        'wasabi_region',
        'wasabi_endpoint',
         'tenant_id',
            'clinet_id',
            'clinet_secret',
        'wasabi_bucket',
         'google_project_id',
            'google_bucket',
            'google_client_email',
            'google_private_key',
            'google_private_key_id',
            'google_client_id',
            'aws_api_key',
            'aws_secret_key',
            'aws_region',
            'aws_bucket',
            'storage_type',
            'webhook_google_secret_id',
            'webhook_google_client_id',
            'webhook_type'
    ];

    foreach ($decryptFields as $field) {
        if (!empty($settingsArray[$field])) {
            $settingsArray[$field] = CryptService::decryptData($settingsArray[$field]);
        }
    }

    if (isset($settingsArray['notification_status']) && $settingsArray['notification_status'] == 0) {
        $settingsArray['notification_tune'] = null;
    }

    $settingsArray = array_map(function ($value) {
        return $value === null ? '' : $value;
    }, $settingsArray);

    return response()->json([
        'success' => true,
        'data' => $settingsArray
    ], 200);
}



public function updateSettings(Request $request)
{
    $id = $request->input('id');
    $subadminId = $request->input('subadmin_id'); // नया field

    // === Find Existing Settings ===
    if ($id) {
        $settings = DB::table('setting')->where('id', $id)->first();
    } else {
        $settings = DB::table('setting')->where('subadmin_id', $subadminId)->first();
        $id = $settings->id ?? null;
    }

    $changes = [];
    $changeDetails = [];

    $oldFavicon = $settings->favicon ?? null;
    $oldLogo = $settings->logo ?? null;
    $oldTune = $settings->notification_tune ?? null;

    // ✅ Favicon Upload
    if ($request->hasFile('favicon')) {
        $faviconName = time() . '_favicon.' . $request->favicon->extension();
        $request->favicon->move(public_path('admin/vendor'), $faviconName);
        $faviconPath = "admin/vendor/{$faviconName}";

        if ($oldFavicon && file_exists(public_path($oldFavicon))) {
            unlink(public_path($oldFavicon));
        }

        if ($faviconPath !== $oldFavicon) {
            $changes[] = 'Favicon updated';
            $changeDetails[] = "favicon | old -> {$oldFavicon}  new -> {$faviconPath}";
        }
    } else {
        $faviconPath = $oldFavicon;
    }

if ($request->hasFile('logo')) {
    $logoName = time() . '_' . uniqid() . '_logo.' . $request->logo->extension();
    $request->logo->move(public_path('admin/vendor'), $logoName);
    $logoPath = "admin/vendor/{$logoName}";

    if ($oldLogo && file_exists(public_path($oldLogo))) {
        unlink(public_path($oldLogo));
    }

    if ($logoPath !== $oldLogo) {
        $changes[] = 'Logo updated';
        $changeDetails[] = "logo | old -> {$oldLogo}  new -> {$logoPath}";
    }
} else {
    $logoPath = $oldLogo;
}

    // ✅ Notification Tune Upload
    if ($request->hasFile('notification_tune')) {
        $allowedExtensions = ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac'];
        $extension = $request->notification_tune->extension();

        if (in_array($extension, $allowedExtensions)) {
            $tuneName = time() . '_tune.' . $extension;
            $request->notification_tune->move(public_path('admin/vendor'), $tuneName);
            $tunePath = "admin/vendor/{$tuneName}";

            if ($oldTune && file_exists(public_path($oldTune))) {
                unlink(public_path($oldTune));
            }

            if ($tunePath !== $oldTune) {
                $changes[] = 'Notification Tune updated';
                $changeDetails[] = "notification_tune | old -> {$oldTune}  new -> {$tunePath}";
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Invalid audio format! Allowed: mp3, wav, ogg, aac, m4a, flac'
            ]);
        }
    } else {
        $tunePath = $oldTune;
    }

    // ✅ Prepare Update Data
    $updateData = [
        'favicon' => $faviconPath,
        'logo' => $logoPath,
        'notification_tune' => $tunePath,
        'subadmin_id' => $subadminId
    ];

    // ✅ Encrypted Fields
    $encryptField = function ($fieldName) use ($request, $settings, &$changes, &$changeDetails) {
        $newValue = $request->input($fieldName);
        if (!empty($newValue)) {
            $existing = $settings ? CryptService::decryptData($settings->$fieldName) : null;
            if ($newValue !== $existing) {
                $changes[] = "{$fieldName} updated";
                $changeDetails[] = "{$fieldName} | old -> {$existing}  new -> {$newValue}";
                return CryptService::encryptData($newValue);
            } else {
                return $settings->$fieldName ?? CryptService::encryptData($newValue);
            }
        }
        return $settings->$fieldName ?? null;
    };

    $updateData['wtsp_pass'] = $encryptField('wtsp_pass');
    $updateData['zoho_from_email'] = $encryptField('zoho_from_email');
    $updateData['sms_api_key'] = $encryptField('sms_api_key');
    $updateData['wtsp_api_key'] = $encryptField('wtsp_api_key');
    $updateData['zoho_api_key'] = $encryptField('zoho_api_key');
    $updateData['sms_secret_key'] = $encryptField('sms_secret_key');
    $updateData['bounce_address'] = $encryptField('bounce_address');
    $updateData['wasabi_secret_key'] = $encryptField('wasabi_secret_key');
    $updateData['wasabi_api_key'] = $encryptField('wasabi_api_key');
    $updateData['wasabi_bucket'] = $encryptField('wasabi_bucket');
    $updateData['wasabi_endpoint'] = $encryptField('wasabi_endpoint');
    $updateData['wasabi_region'] = $encryptField('wasabi_region');
    $updateData['tenant_id'] = $encryptField('tenant_id');
    $updateData['clinet_id'] = $encryptField('clinet_id');
    $updateData['clinet_secret'] = $encryptField('clinet_secret');
    $updateData['aws_api_key'] = $encryptField('aws_api_key');
    $updateData['aws_secret_key'] = $encryptField('aws_secret_key');
    $updateData['aws_region'] = $encryptField('aws_region');
    $updateData['aws_bucket'] = $encryptField('aws_bucket');
    $updateData['google_project_id'] = $encryptField('google_project_id');
    $updateData['google_bucket'] = $encryptField('google_bucket');
    $updateData['google_client_email'] = $encryptField('google_client_email');
    $updateData['google_private_key'] = $encryptField('google_private_key');
    $updateData['google_private_key_id'] = $encryptField('google_private_key_id');
    $updateData['google_client_id'] = $encryptField('google_client_id');

    $updateData['webhook_google_client_id'] = $encryptField('webhook_google_client_id');
    $updateData['webhook_google_secret_id'] = $encryptField('webhook_google_secret_id');


    // ✅ Plain Fields
    $plainFields = [
        'last_notification_template',
        'last_notification_minutes',
        'first_notification_template',
        'first_notification_minutes',
        'closure_notification_template',
        'closure_notification_minutes',
        'notification_status',
        'storage_type',
        'webhook_type'
    ];

    foreach ($plainFields as $field) {
        $newVal = $request->input($field);
        $oldVal = $settings->$field ?? null;

        if ($newVal !== null && $newVal != $oldVal) {
            $changes[] = "{$field} updated";
            $changeDetails[] = "{$field} | old -> {$oldVal}  new -> {$newVal}";
            $updateData[$field] = $newVal;
        } else {
            $updateData[$field] = $oldVal;
        }
    }

    // ✅ Insert / Update Logic
    if (empty($changes) && !$id) {
        return response()->json([
            'status' => false,
            'message' => 'No data to insert!'
        ]);
    }

    if ($id && $settings) {
        DB::table('setting')->where('id', $id)->update($updateData);
        $actionMsg = 'Settings updated successfully!';
    } else {
        $id = DB::table('setting')->insertGetId($updateData);
        $actionMsg = 'Settings inserted successfully!';
    }

    // ✅ Activity Log
    $logAction = CryptService::encryptData("Settings updated");
    $logAction1 = CustomCipherService::encryptData("Settings updated");

    $logMessage = CryptService::encryptData('Changes: ' . implode(' | ', $changeDetails));
    $logMessage1 = CustomCipherService::encryptData('Changes: ' . implode(' | ', $changeDetails));

    $logDetails = CryptService::encryptData(json_encode([
        'changes' => $changeDetails
    ]));

    DB::table('activities')->insert([
        'action' => $logAction,
        's_action' => $logAction1,
        'user_id' => $request->admin_id,
        'message' => $logMessage,
        's_message' => $logMessage1,
        'details' => $logDetails,
        'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
        'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
    ]);

    return response()->json([
        'status' => true,
        'message' => $actionMsg,
        'id' => $id
    ]);
}







    
public function getLogos(Request $request)
{
    $sId = $request->input('s_id');
    $subadminId = $request->input('subadmin_id');
    $superadmin = DB::table('superadmins')->where('id', $sId)->first();

    if (!empty($subadminId)) {
           if ($superadmin->login_type == 1) {
             $settings = DB::table('setting')
            ->select('logo', 'favicon')
            ->first();
        } else {
            $settings = DB::table('setting')
                ->select('logo', 'favicon')
                ->where('subadmin_id', $subadminId)
                ->first();
        }

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'No settings found'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => $settings
        ], 200);
    }

    

    if (!$superadmin) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid superadmin id'
        ], 404);
    }
   
    if ($superadmin->login_type == 1) {
        $settings = DB::table('setting')
            ->select('logo', 'favicon')
            ->first();
    } else {
        $settings = DB::table('setting')
            ->select('logo', 'favicon')
            ->where('subadmin_id', $superadmin->subadmin_id)
            ->first();
    }

    if (!$settings) {
        return response()->json([
            'success' => false,
            'message' => 'No settings found'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'dd'=>$superadmin->login_type,
        'data' => $settings
    ], 200);
}


public function getLogo(Request $request)
{
    try {

        $settings = DB::table('setting')
            ->select('logo', 'favicon', 'dark_logo')
            ->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'No settings found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'logo' => $settings->logo ?? '',
                'favicon' => $settings->favicon ?? '',
                'dark_logo' => $settings->dark_logo ?? ''
            ]
        ], 200);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => 'Settings fetch failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
  
    
}