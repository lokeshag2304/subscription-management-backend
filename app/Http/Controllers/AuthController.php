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

    $superadmins = DB::table('superadmins')
        ->select('id', 'email', 'password', 'login_type', 'name', 'profile', 'status', 'subadmin_id')
        ->get();

    $matchedAdmin = null;

    foreach ($superadmins as $admin) {
        $decryptedEmail = CryptService::decryptData($admin->email);

        if ($decryptedEmail === $inputEmail) {
            $matchedAdmin = $admin;
            break;
        }
    }

    if (!$matchedAdmin) {
        return response()->json(['message' => 'Invalid credentials']);
    }

    if ($matchedAdmin->status == 0) {
        return response()->json(['message' => 'Your account has been deactivated']);
    }

    $passwordMatch = Hash::check($inputPassword, $matchedAdmin->password) || $inputPassword === $matchedAdmin->password;

    if (!$passwordMatch) {
        return response()->json(['message' => 'Invalid credentials']);
    }

    $token = Str::random(60);
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
        'whatsapp_status' => $setting->whatsapp_status,
        'email_status' => $setting->email_status,
        'sms_status' => $setting->sms_status,

        // Role mapping
        'role' => [
            1 => 'SuperAdmin',
            2 => 'User',
            3 => 'Client'
        ][$matchedAdmin->login_type] ?? 'Unknown',

        'subadmin_id' => $matchedAdmin->login_type == 2 ? $matchedAdmin->subadmin_id : null
    ]);
}




public function two_step_otp(Request $request)
{
    $data = json_decode($request->getContent(), true);
    $contact = $data['contact'] ?? null;
    $otp_type = $data['method'] ?? null;
    $otp = rand(1000, 9999);
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
        ->select('id', 'name', 'email', 'number', 'profile', 'two_step_auth', 'login_type')
        ->first();

    if (!empty($user)) {
        // ✅ Decrypt the values
        $user->name = CryptService::decryptData($user->name);
        $user->email = CryptService::decryptData($user->email);
        $user->number = CryptService::decryptData($user->number);

        $forget_string = Str::random(200);
        $changepasswordurl = "http://localhost:3000/auth/change-password/" . $forget_string;

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update([
                'forget_string' => $forget_string
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
    $plainMobile = $request['number'];
    $smsCode = $request['sms_code'] ?? '';

    $encryptedMobile = CryptService::encryptData($plainMobile);

    $user = DB::table('superadmins')
        ->where('number', $encryptedMobile)
        ->first(); 

    $setting = DB::table("setting")->first(); 

    if (!empty($user)) {
        $otp = rand(1000, 9999);
        // $otp = "1234"; // for testing

        $decryptedNumber = CryptService::decryptData($user->number);
        $mobile = $smsCode . $decryptedNumber;
        $last_three = Str::substr($decryptedNumber, -3);

        $this->sendSMSOtp($otp, $mobile);

        DB::table('superadmins')
            ->where('id', $user->id)
            ->update(['otp' => $otp]);

        $message = "We have sent an OTP on your registered mobile number *******" . $last_three . " successfully";

        return response()->json([
            "status" => true,
            "message" => $message,
            "Data" => $user,
        ]);
    } else {
        return response()->json([
            "status" => false,
            "message" => "Mobile Number does not exist in records"
        ]);
    }
}
  
   public function send_whatsap_otp()
{
    $request = json_decode(request()->getContent(), true);
    $plainMobile = $request['number'];
    $whatsappCode = $request['whatsapp_code'] ?? '';

    // ✅ Encrypt number for DB match
    $encryptedMobile = CryptService::encryptData($plainMobile);
    
    $user = DB::table('superadmins')
        ->where('number', $encryptedMobile)
        ->first(); 

    $setting = DB::table("setting")->first(); 

    if (!empty($user)) {
        $otp = rand(1000, 9999);
        // $otp = "1234"; // testing

        // ✅ Decrypt number for use
        $decryptedNumber = CryptService::decryptData($user->number);
        $mobile = $whatsappCode . $decryptedNumber;
        $last_three = Str::substr($decryptedNumber, -3);

        // ✅ Send WhatsApp OTP
        $this->sendOtp($otp, $mobile);

        // ✅ Store OTP
        DB::table('superadmins')
            ->where('id', $user->id)
            ->update(['otp' => $otp]);

        $message = "We have sent an OTP on your registered mobile number *******" . $last_three . " successfully";

        return response()->json([
            "status" => true,
            "message" => $message,
            "Data" => $user,
        ]);
    } else {
        return response()->json([
            "status" => false,
            "message" => "Mobile Number does not exist in records"
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
