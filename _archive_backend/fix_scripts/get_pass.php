<?php
use App\Services\CryptService;
use Illuminate\Support\Facades\DB;

$users = DB::table("superadmins")->get();
foreach ($users as $user) {
    try {
        $email = CryptService::decryptData($user->email);
        $pass = !empty($user->d_password) ? CryptService::decryptData($user->d_password) : $user->password;
        if (stripos($email, "fsivignesh") !== false || stripos($email, "agarwal") !== false) {
            echo "Match found!\nEmail: " . $email . "\nPassword: " . $pass . "\n\n";
        }
    } catch (\Exception $e) {}
}

