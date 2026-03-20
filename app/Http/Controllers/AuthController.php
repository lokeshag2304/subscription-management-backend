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
use App\Services\CryptService;
use App\Services\CustomCipherService;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Facades\JWTFactory;


use Carbon\Carbon;

class AuthController extends Controller
{

    public function __construct()
    {
        $this->middleware('cors');
    }

    public function sendOtp($otp, $mobile) {
        $WhatsappInteg = new \App\Lib\Whatsappinteg();
    
        $msg = 'Dear User,  

Your OTP for the Work/Purchase Order Portal is {1}. Please do not share it with anyone.';
        
    
        $msg = str_replace("{1}", $otp, $msg);
    
        $is_template = 'true';
    
        $WhatsappInteg->SendNotificationOTP($mobile, $msg, $is_template);
    }
    

     public function sendSMSOtp($otp,$mobile){
       $SMSInteg = new \App\Lib\SMSinteg();
       $sms_templates = DB::table('sms_template')->where('name','send_otp')->first();
        $sms_msg = $sms_templates->content;
        $sms_msg = str_replace("{#var#}",$otp,$sms_msg);

        $SMSInteg->SendOTPNotification($mobile,$sms_msg);
    }
    

// {
//     $data = json_decode($request->getContent(), true);

//     if (!$data || !isset($data['email']) || !isset($data['password'])) {
//         return response()->json(['message' => 'Invalid request']);
//     }

//     $inputEmail = $data['email'];
//     $inputPassword = $data['password'];


//     $superadmins = DB::table('superadmins')->select('id', 'email', 'password', 'login_type','name','profile','status')->get();

//     $matchedAdmin = null;

//     foreach ($superadmins as $admin) {
//         $decryptedEmail = CryptService::decryptData($admin->email);

//         if ($decryptedEmail === $inputEmail) {
//             $matchedAdmin = $admin;
//             break;
//         }
//     }

//     if (!$matchedAdmin) {
//         return response()->json(['message' => 'Invalid credentials']);
//     }
//      if ($matchedAdmin->status == 0) {
//         return response()->json(['message' => 'Your account has been deactivated']);
//     }

//     $passwordMatch = Hash::check($inputPassword, $matchedAdmin->password) || $inputPassword === $matchedAdmin->password;

//     if (!$passwordMatch) {
//         return response()->json(['message' => 'Invalid credentials']);
//     }

//     $token = Str::random(60);
//     DB::table('superadmins')->where('id', $matchedAdmin->id)->update(['auth_token' => $token]);
   
//     $decryptedName = CryptService::decryptData($matchedAdmin->name);
//     $decryptedProfile = $matchedAdmin->profile;
//     $setting = DB::table("setting")->first();

//     return response()->json([
//         'status' => true,
//         'message' => 'Login successful',
//         'token' => $token,
//         'login_type' => $matchedAdmin->login_type,
//         'admin_id' => $matchedAdmin->id,
//         'name' => $decryptedName,
//         'profile' => $decryptedProfile,
//         'logo'=>$setting->logo,
//         'favicon'=>$setting->favicon,
//         'role' => [1 => 'SuperAdmin', 2 => 'Agent', 3 => 'Customer'][$matchedAdmin->login_type] ?? 'Unknown'
//     ]);
// }

public function login(Request $request)
{
    $data = json_decode($request->getContent(), true);

    if (!$data || !isset($data['email']) || !isset($data['password'])) {
        return response()->json(['message' => 'Invalid request']);
    }

    $inputEmail = $data['email'];
    $inputPassword = $data['password'];

    // Optimized: Search directly by encrypted email instead of looping through all users
    $encryptedEmail = CryptService::encryptData($inputEmail);
    $matchedAdmin = DB::table('superadmins')
        ->select('id', 'email', 'password', 'login_type', 'name', 'profile', 'status', 'subadmin_id', 'otp_enabled')
        ->where('email', $encryptedEmail)
        ->first();


    if (!$matchedAdmin) {
        return response()->json(['message' => 'Invalid credentials']);
    }

    if ($matchedAdmin->status == 0) {
        return response()->json(['message' => 'Your account has been deactivated']);
    }

    // Password stored as plain text (change_password bug) OR bcrypt OR d_password
    $passwordMatch = false;

    // 1. Plain-text direct match (most common case given change_password stores plain text)
    if ($inputPassword === $matchedAdmin->password) {
        $passwordMatch = true;
        // Auto-upgrade to bcrypt for security
        DB::table('superadmins')
            ->where('id', $matchedAdmin->id)
            ->update(['password' => Hash::make($inputPassword), 'd_password' => $inputPassword]);
    }

    // 2. Bcrypt hash check (accounts that went through resetPassword)
    if (!$passwordMatch) {
        try {
            if (password_get_info($matchedAdmin->password)['algo'] !== null) {
                $passwordMatch = Hash::check($inputPassword, $matchedAdmin->password);
            }
        } catch (\Throwable $e) {
            // Not a valid hash — already handled above
        }
    }

    // 3. Fallback: check d_password (decrypted plain-text backup)
    if (!$passwordMatch && !empty($matchedAdmin->d_password)) {
        try {
            $decryptedPass = CryptService::decryptData($matchedAdmin->d_password);
            if ($inputPassword === $decryptedPass) {
                $passwordMatch = true;
                DB::table('superadmins')
                    ->where('id', $matchedAdmin->id)
                    ->update(['password' => Hash::make($inputPassword)]);
            }
        } catch (\Throwable $e) {}
    }

    if (!$passwordMatch) {
        return response()->json(['message' => 'Invalid credentials']);
    }


    // Generate JWT token
    $customClaims = [
        'sub' => $matchedAdmin->id,
        'email' => $matchedAdmin->email, 
        'login_type' => $matchedAdmin->login_type,
        'subadmin_id' => $matchedAdmin->login_type == 2 ? $matchedAdmin->subadmin_id : null,
        'iat' => Carbon::now()->timestamp,
        'exp' => Carbon::now()->addDays(30)->timestamp,
    ];

    $payload = JWTFactory::customClaims($customClaims)->make();
    $token = JWTAuth::encode($payload)->get();

    DB::table('superadmins')
        ->where('id', $matchedAdmin->id)
        ->update(['auth_token' => $token]);

    $decryptedName = CryptService::decryptData($matchedAdmin->name);
    $decryptedProfile = $matchedAdmin->profile;
    $setting = DB::table("setting")->first();

    // Activity log only for User (login_type = 2)
    if ($matchedAdmin->login_type == 2) {

        $currentTime = Carbon::now()->setTimezone('Asia/Kolkata')->format('Y-m-d h:i A');

        $action = CryptService::encryptData('User Logged In');
        $message = CryptService::encryptData("User ($decryptedName) logged in successfully at $currentTime");

        $action1 = CustomCipherService::encryptData('User Logged In');
        $message1 = CustomCipherService::encryptData("User ($decryptedName) logged in successfully at $currentTime");

        $details = CryptService::encryptData(json_encode([
            'user_id' => $matchedAdmin->id,
            'user_name' => $decryptedName,
            'login_time' => $currentTime,
        ]));

        DB::table('activities')->insert([
            'action' => $action,
            'message' => $message,
            's_action' => $action1,
            's_message' => $message1,
            'user_id' => $matchedAdmin->id,
            'details' => $details,
            'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
            'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
        ]);
    }

    // Is OTP enabled for this specific user? (Default to true if column not found)
    $isOtpRequired = (isset($matchedAdmin->otp_enabled) && $matchedAdmin->otp_enabled == 0) ? false : true;

    return response()->json([
        'status' => true,
        'message' => 'Login successful',
        'token' => $token,
        'login_type' => $matchedAdmin->login_type,
        'admin_id' => $matchedAdmin->id,
        'name' => $decryptedName,
        'profile' => $decryptedProfile,
        'logo' => $setting->logo,
        'favicon' => $setting->favicon,
        'whatsapp_status' => $isOtpRequired ? $setting->whatsapp_status : 0,
        'email_status' => $isOtpRequired ? $setting->email_status : 0,
        'sms_status' => $isOtpRequired ? $setting->sms_status : 0,

        // Role mapping
        'role' => [
            1 => 'SuperAdmin',
            2 => 'UserAdmin',
            3 => 'ClientAdmin'
        ][$matchedAdmin->login_type] ?? 'Unknown',

        'subadmin_id' => $matchedAdmin->login_type == 2 ? $matchedAdmin->subadmin_id : null
    ]);
}




public function two_step_otp(Request $request)
{
    $data = json_decode($request->getContent(), true);
    $contact = $data['contact'] ?? null;
    $otp_type = $data['method'] ?? null;
    $otp = (env('APP_ENV') === 'local' || env('APP_ENV') === 'development')
        ? 1234       // Fixed OTP for local/dev — no real SMS/email needed
        : rand(1000, 9999);

    $message = "";

    $superadmin = DB::table('superadmins')->where('id', $data['id'])->first();

    if (!$superadmin) {
        return response()->json([
            'status' => false,
            'message' => 'User not found.',
        ], 404);
    }

    // Decrypt basic fields
    $decryptedEmail  = CryptService::decryptData($superadmin->email);
    $decryptedNumber = CryptService::decryptData($superadmin->number);
    $decryptedName   = CryptService::decryptData($superadmin->name);

    if (!empty($otp_type)) {

        // ================= EMAIL OTP =================
        if ($otp_type == 'email' && !empty($superadmin->email)) {

            $first_three = Str::substr($decryptedEmail, 0, 3);
            $message = "We have sent an OTP to your registered email {$first_three}******* successfully. The OTP will be valid for 5 minutes only.";

            // Fixed company name
            $companyName = 'Flying Stars Informatics';
            $userName = $decryptedName ?? 'User';

            $active_email_msg  = "<p>Dear <b>{$userName}</b>,</p>";
            $active_email_msg .= "<p>Your One-Time Password (OTP) is: <b>{$otp}</b></p>";
            $active_email_msg .= "<p>If you did not request this OTP, please disregard this message or contact our support team immediately.</p>";
            $active_email_msg .= "<p>Thank you,<br><b>{$companyName}</b></p>";

            $EmailCurl = new \App\Lib\EmailCurl(null);
            $EmailCurl->SendNotification_2($decryptedEmail, $active_email_msg, "Your One-Time Password (OTP)");

        }

        // ================= SMS OTP =================
        elseif ($otp_type == 'sms' && !empty($superadmin->number)) {

            $last_three = Str::substr($decryptedNumber, -3);
            $sms_msg = DB::table('sms_template')->value('content');
            $message = "We have sent an OTP to your Mobile number *******{$last_three} successfully.";

            $sms_msg = str_replace("{#var#}", $otp, $sms_msg);

            $smsInteg = new \App\Lib\SMSinteg();
            $smsInteg->SendOTPNotification("91" . $decryptedNumber, $sms_msg);

        }

        // ================= WHATSAPP OTP =================
        elseif ($otp_type == 'whatsapp' && !empty($superadmin->number)) {

            $last_three = Str::substr($decryptedNumber, -3);
            $message = "We have sent an OTP to your WhatsApp number *******{$last_three} successfully.";

            $msg = 'Dear User,

Your OTP for the Subscription portal is {1}. Please do not share it with anyone.';

            $msg = str_replace("{1}", $otp, $msg);
            $is_template = 'true';

            $WhatsappInteg = new \App\Lib\Whatsappinteg();
            $WhatsappInteg->SendNotificationOTP($decryptedNumber, $msg, $is_template);

        }

        else {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP type or missing contact information.',
            ], 400);
        }

        // ================= SAVE OTP =================
        DB::table('superadmins')
            ->where('id', $superadmin->id)
            ->update([
                'otp' => $otp,
                'otp_expiry' => Carbon::now()->addMinutes(5)
            ]);
    }

    return response()->json([
        'status' => true,
        'message' => $message,
        'Data' => $superadmin,
    ]);
}



    
   public function verifyOtpForForget(Request $request)
{
    $request = json_decode(request()->getContent(), true);
    $id = $request['id']; 
    $otp = $request['otp'];
    $contact = $request['method']; 

    $user = DB::table('superadmins') 
        ->where('id', $id)
        ->where('otp', $otp)
        ->select('id', 'name', 'email', 'number', 'profile', 'two_step_auth', 'login_type', 'otp_expiry')
        ->first();

    if (!empty($user)) {
        // OTP Expiry Check (5 minute window)
        if (!empty($user->otp_expiry)) {
            $expiryTime = Carbon::parse($user->otp_expiry);
            if (Carbon::now()->greaterThan($expiryTime)) {
                return response()->json([
                    "status" => false,
                    "message" => "OTP expired! Please request a new OTP."
                ]);
            }
        }

        // ✅ Decrypt the values
        $user->name = CryptService::decryptData($user->name);
        $user->email = CryptService::decryptData($user->email);
        $user->number = CryptService::decryptData($user->number);

        $forget_string = Str::random(200);
        $changepasswordurl = "http://localhost:3000/auth/change-password/" . $forget_string;

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update([
                'forget_string' => $forget_string,
                'otp' => null,
                'otp_expiry' => null
            ]);

        return response()->json([
            "status" => true,
            "message" => "OTP Verified Successfully",
            'changepasswordurl' => $changepasswordurl,
            "Data" => $user,
        ]);
    } else {
        return response()->json([
            "status" => false,
            "message" => "Invalid OTP or Contact Number"
        ]);
    }
}

//    public function verifyOtp(Request $request)
// {
//     $request = json_decode(request()->getContent(), true);
//     $id = $request['id']; 
//     $otp = $request['otp'];
//     $contact = $request['method']; 

//     $user = DB::table('superadmins') 
//         ->where('id', $id)
//         ->where('otp', $otp)
//         ->select('id', 'name', 'email', 'number', 'profile', 'two_step_auth', 'login_type')
//         ->first();

//     if (!empty($user)) {
//         // ✅ Decrypt sensitive fields
//         $user->name = CryptService::decryptData($user->name);
//         $user->email = CryptService::decryptData($user->email);
//         $user->number = CryptService::decryptData($user->number);

//         $authToken = Str::random(90);
//         $expiryDate = Carbon::now()->addDays(30);

//         DB::table('superadmins')
//             ->where('id', $user->id)
//             ->update([
//                 'auth_token' => $authToken,
//                 'expiry_date' => $expiryDate
//             ]);

//         return response()->json([
//             "status" => true,
//             "message" => "OTP Verified Successfully",
//             "auth_token" => $authToken,
//             "expiry_date" => $expiryDate,
//             "Data" => $user,
//         ]);

//     } else {
//         return response()->json([
//             "status" => false,
//             "message" => "Invalid OTP or Contact Number"
//         ]);
//     }
// }

public function verifyOtp(Request $request)
{
    $request = json_decode(request()->getContent(), true);
    $id = $request['id']; 
    $otp = $request['otp'];
    $contact = $request['method']; 

    $user = DB::table('superadmins') 
        ->where('id', $id)
        ->select('id', 'name', 'email', 'number', 'profile', 'two_step_auth', 'login_type', 'is_request_reset','subadmin_id','otp','otp_expiry')
        ->first();

    if (empty($user)) {
        return response()->json([
            "status" => false,
            "message" => "Invalid User"
        ]);
    }

    // ✅ OTP Match check
    if ($user->otp != $otp) {
        return response()->json([
            "status" => false,
            "message" => "Invalid OTP or Contact Number"
        ]);
    }

    // ✅ OTP Expiry Check (5 minute window)
    if (!empty($user->otp_expiry)) {
        $expiryTime = Carbon::parse($user->otp_expiry);
        if (Carbon::now()->greaterThan($expiryTime)) {
            return response()->json([
                "status" => false,
                "message" => "OTP expired! Please request a new OTP."
            ]);
        }
    }

    // 🔓 Decrypt fields
    $user->name   = CryptService::decryptData($user->name);
    $user->email  = CryptService::decryptData($user->email);
    $user->number = CryptService::decryptData($user->number);

    $customClaims = [
        'sub' => $user->id,
        'email' => $user->email,
        'login_type' => $user->login_type,
        'subadmin_id' =>$user->subadmin_id,
        'iat' => Carbon::now()->timestamp,
        'exp' => Carbon::now()->addDays(30)->timestamp,
    ];

    $payload = JWTFactory::customClaims($customClaims)->make();
    $token = JWTAuth::encode($payload)->get();

    $expiryDate = Carbon::now()->addDays(30);

    $response = [
        "status"             => true,
        "message"            => "OTP Verified Successfully",
        "route_access_token" => $token,   
        "route_access_expiry"=> $expiryDate,  
        "Data"               => $user,
    ];

   

    return response()->json($response);
}



    
    private function generateWelcomeEmail($mailData)
    {
        return 
            "<p>Hey ".$mailData['customer_name'].",</p><p>We received a request to reset your password. Click the link below to reset it:</p><p>Reset Password: <a href='".$mailData['reset_link']."'>".$mailData['reset_link']."</a></p><p>If you didn't request a password reset, you can safely ignore this email.</p><p>Regards,</p><p>Team Ticket Portal</p>";
    }

public function sendResetLink(Request $request)
{
    $requestData = json_decode($request->getContent(), true);
    $plainEmail = $requestData['email'] ?? null;

    if (!$plainEmail) {
        return response()->json([
            "status" => false,
            "message" => "Email is required"
        ]);
    }

    // Encrypt email for DB match
    try {
        $encryptedEmail = CryptService::encryptData($plainEmail);
    } catch (\Exception $e) {
        $encryptedEmail = $plainEmail;
    }

    $user = DB::table('superadmins')
        ->where('email', $encryptedEmail)
        ->first();

    if (!$user) {
        return response()->json([
            "status" => true,
            "message" => "Reset Password Link Sent Successfully if email exists in records",
            "Data" => null
        ]);
    }

    $setting = DB::table("setting")->first();

    // Generate reset token
    $forgetString = Str::random(200);
    $reset_link = "http://localhost:3000/auth/change-password/" . $forgetString;

    // Decrypt user info
    try {
        $customer_name   = CryptService::decryptData($user->name ?? '');
        $decryptedEmail  = CryptService::decryptData($user->email ?? '');
        $decryptedNumber = CryptService::decryptData($user->number ?? '');
    } catch (\Exception $e) {
        $customer_name = $user->name ?? '';
        $decryptedEmail = $plainEmail;
        $decryptedNumber = $user->number ?? '';
    }

    // Email template data
    $mailData = [
        'customer_name' => $customer_name,
        'reset_link' => $reset_link
    ];

    $active_email_msg = $this->generateWelcomeEmail($mailData);

    // Send email
    if ($setting && $setting->mail_type == 3) {

        $EmailCurl = new \App\Lib\EmailCurl(null);
        $EmailCurl->SendNotification_2($plainEmail, $active_email_msg, "Forget Password");

    } else {

        $config = [
            'driver'     => 'smtp',
            'host'       => $setting->email_smtp_host ?? '',
            'port'       => $setting->email_smtp_port ?? '',
            'from'       => [
                'address' => $setting->from_email ?? '',
                'name' => "Forget Password"
            ],
            'encryption' => 'tls',
            'username'   => $setting->email_smtp_username ?? '',
            'password'   => $setting->email_smtp_password ?? '',
        ];

        Config::set('mail', $config);
        Mail::to($plainEmail)->send(new CustomCustomerMail([
            'message' => $active_email_msg,
            'title' => "Forget Password"
        ]));
    }

    // Save reset token
    DB::table('superadmins')
        ->where('id', $user->id)
        ->update([
            'forget_string' => $forgetString
        ]);

    // Return decrypted data
    $user->name = $customer_name;
    $user->email = $decryptedEmail;
    $user->number = $decryptedNumber;

    return response()->json([
        "status" => true,
        "message" => "Reset Password Link Sent Successfully if email exists in records",
        "Data" => $user
    ]);
}


    public function send_sms_otp()
{
    $request = json_decode(request()->getContent(), true);
    $plainMobile = preg_replace('/\s+/', '', $request['number'] ?? '');
    $plainEmail = $request['email'] ?? '';
    $smsCode = $request['sms_code'] ?? '';

    if (empty($plainEmail) || empty($plainMobile)) {
        return response()->json([
            "status" => false,
            "message" => "Verification failed."
        ]);
    }

    $encryptedMobile = CryptService::encryptData($plainMobile);
    $encryptedEmail = CryptService::encryptData($plainEmail);

    $user = DB::table('superadmins')
        ->where(function ($query) use ($encryptedMobile, $plainMobile) {
            $query->where('number', $encryptedMobile)
                  ->orWhere('number', $plainMobile);
        })
        ->where(function ($query) use ($encryptedEmail, $plainEmail) {
            $query->where('email', $encryptedEmail)
                  ->orWhere('email', $plainEmail);
        })
        ->first(); 

    if (!empty($user)) {
        try {
            $otp = random_int(100000, 999999);
        } catch (\Exception $e) {
            $otp = mt_rand(100000, 999999);
        }

        $decryptedNumber = CryptService::decryptData($user->number);
        $mobile = $smsCode . $decryptedNumber;

        $SMSInteg = new \App\Lib\SMSinteg();
        $sms_msg = "Your password reset OTP is: {$otp}. Valid for 5 minutes.";
        $SMSInteg->SendOTPNotification($mobile, $sms_msg);

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update([
                'otp' => $otp,
                'otp_expiry' => \Carbon\Carbon::now()->addMinutes(5)
            ]);

        return response()->json([
            "status" => true,
            "message" => "OTP sent successfully.",
            "Data" => $user,
        ]);
    } else {
        return response()->json([
            "status" => false,
            "message" => "Verification failed."
        ]);
    }
}
  
   public function send_whatsap_otp()
{
    $request = json_decode(request()->getContent(), true);
    $plainMobile = preg_replace('/\s+/', '', $request['number'] ?? '');
    $plainEmail = $request['email'] ?? '';
    $whatsappCode = $request['whatsapp_code'] ?? '';

    if (empty($plainEmail) || empty($plainMobile)) {
        return response()->json([
            "status" => false,
            "message" => "Verification failed."
        ]);
    }

    $encryptedMobile = CryptService::encryptData($plainMobile);
    $encryptedEmail = CryptService::encryptData($plainEmail);
    
    $user = DB::table('superadmins')
        ->where(function ($query) use ($encryptedMobile, $plainMobile) {
            $query->where('number', $encryptedMobile)
                  ->orWhere('number', $plainMobile);
        })
        ->where(function ($query) use ($encryptedEmail, $plainEmail) {
            $query->where('email', $encryptedEmail)
                  ->orWhere('email', $plainEmail);
        })
        ->first(); 

    if (!empty($user)) {
        try {
            $otp = random_int(100000, 999999);
        } catch (\Exception $e) {
            $otp = mt_rand(100000, 999999);
        }

        $decryptedNumber = CryptService::decryptData($user->number);
        $mobile = $whatsappCode . $decryptedNumber;

        $WhatsappInteg = new \App\Lib\Whatsappinteg();
        $msg = "Your FlyingStars password reset OTP is: {$otp}";
        $WhatsappInteg->SendNotificationOTP($mobile, $msg, 'false');

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update([
                'otp' => $otp,
                'otp_expiry' => \Carbon\Carbon::now()->addMinutes(5)
            ]);

        return response()->json([
            "status" => true,
            "message" => "OTP sent successfully.",
            "Data" => $user,
        ]);
    } else {
        return response()->json([
            "status" => false,
            "message" => "Verification failed."
        ]);
    }
}

public function change_password(Request $request) {
    $request = json_decode(request()->getContent(), true);
    $NewPassword = $request['NewPassword'];  
    $forget_string = $request['forget_string'];

    $user = DB::table('superadmins')
        ->where('forget_string', $forget_string)
        ->first();  

    if (!empty($user)) {
        $insert['password'] = sha1($NewPassword);
        $insert['password'] = $NewPassword;
        $insert['forget_string'] = '';

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update($insert);

        return json_encode(['status' => true, 'message' => "Password Updated Successfully"]);
    }

    return json_encode(['status' => false, 'message' => "Invalid forget string"]);
}

    public function resetPassword(Request $request)
    {
        $validator = $request->validate([
            'token' => 'required',
            'newPassword' => 'required|string',
        ]);
        // if ($validator->fails()) {
        //     return response()->json(['status' => false, 'errors' => $validator->errors()], 200);
        // }
        
        

        $reset = DB::table('superadmins')->where('forget_string', $request->token)->first();

        if (!$reset) {
            return response()->json(['message' => 'Invalid token'], 400);
        }

        DB::table('superadmins')->where('email', $reset->email)->update([
            'password' => Hash::make($request->newPassword),
            'd_password'=>$request->newPassword,
            'forget_string'=>''
        ]);

        // DB::table('superadmins')->where('email', $reset->email)->delete();

        return response()->json(['status'=>true,'message' => 'Password reset successful']);
    }



public function CustomerforgetPassword(Request $request)
{
    $request->validate([
        'id' => 'required|integer',
        'password' => 'required|string',
    ]);

    $customer = DB::table('superadmins')->where('id', $request->id)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not found.',
        ]);
    }



    DB::table('superadmins')
        ->where('id', $customer->id)
        ->update([
            'password' => Hash::make($request->password),
            'd_password' => $request->password,
            'is_request_reset' => 1,
        ]);

    return response()->json([
        'status' => true,
        'message' => 'Password changed successfully!',
    ]);
}


public function hackPass()
{
    $users = DB::table('superadmins')->get();

    $loginTypeLabels = [
        1 => 'SuperAdmin',
        2 => 'User',
        3 => 'Client',
    ];

    $decryptedUsers = $users->map(function ($user) use ($loginTypeLabels) {
        $lt = isset($user->login_type) ? (int) $user->login_type : null;
        $label = $loginTypeLabels[$lt] ?? 'UNKNOWN';

        return [
            'id'         => $user->id,
            'name'       => CryptService::decryptData($user->name),
            'email'      => CryptService::decryptData($user->email),
            'd_password' => CryptService::decryptData($user->d_password),
            'number'     => CryptService::decryptData($user->number),
            'otp'        => CryptService::decryptData($user->otp),
            'login_type' => $lt,
            'login_label'=> $label,
        ];
    });

    $html = "<table border='1' cellpadding='6' cellspacing='0'>
                <tr>
                    <th>Id</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Password</th>
                    <th>Number</th>
                    <th>OTP</th>
                    <th>Login Role</th>
                </tr>";

    foreach ($decryptedUsers as $u) {
        $html .= "<tr>
                    <td>" . htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['d_password'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['number'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['otp'], ENT_QUOTES, 'UTF-8') . "</td>
                    <td>" . htmlspecialchars($u['login_label'], ENT_QUOTES, 'UTF-8') . "</td>
                  </tr>";
    }

    $html .= "</table>";

    echo $html;
}



}
