<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\CryptService;

$name     = 'Test User';
$email    = 'testuser@flyingstars.local';
$phone    = '9000000001';
$password = 'Test@1234';
$address  = 'Test Office, Indore';

// Encrypt for DB
$encName    = CryptService::encryptData($name);
$encEmail   = CryptService::encryptData($email);
$encPhone   = CryptService::encryptData($phone);
$encAddress = CryptService::encryptData($address);
$encPass    = CryptService::encryptData($password);

// Check if already exists
$existing = DB::table('superadmins')->where('email', $encEmail)->first();
if ($existing) {
    echo "User already exists with id: {$existing->id}\n";
    // Update password in case it changed
    DB::table('superadmins')->where('id', $existing->id)->update([
        'password'   => Hash::make($password),
        'd_password' => $encPass,
        'status'     => 1,
    ]);
    echo "Password reset to: $password\n";
    file_put_contents('test_user_creds.txt',
        "=== TEST USER ACCOUNT ===\n" .
        "Email:    $email\n" .
        "Password: $password\n" .
        "Role:     User (login_type=2)\n" .
        "DB ID:    {$existing->id}\n"
    );
    exit(0);
}

$id = DB::table('superadmins')->insertGetId([
    'name'       => $encName,
    'email'      => $encEmail,
    'number'     => $encPhone,
    'address'    => $encAddress,
    'password'   => Hash::make($password),
    'd_password' => $encPass,
    'profile'    => 'admin/logo/dummy.jpeg',
    'login_type' => 2,           // 2 = User (not SuperAdmin=1, not Client=3)
    'status'     => 1,
    'added_by'   => 291,         // flyingstars.informatics (superadmin)
    'created_at' => now(),
]);

echo "Created test user with id: $id\n";
file_put_contents('test_user_creds.txt',
    "=== TEST USER ACCOUNT ===\n" .
    "Email:    $email\n" .
    "Password: $password\n" .
    "Role:     User (login_type=2)\n" .
    "DB ID:    $id\n"
);
echo "Credentials saved to test_user_creds.txt\n";
