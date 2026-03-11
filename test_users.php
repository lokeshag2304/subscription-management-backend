<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

$users = DB::table('superadmins')
    ->whereNotNull('email')
    ->whereNotNull('number')
    ->where('number', '!=', '')
    ->where('email', '!=', '')
    ->orderBy('id', 'desc')
    ->limit(3)
    ->get();

$i = 1;
foreach($users as $user){
    try {
        $email = CryptService::decryptData($user->email);
        $number = CryptService::decryptData($user->number);
        echo "User {$i}:\n";
        echo "Email: {$email}\n";
        echo "Phone: {$number}\n";
        echo "-------------------\n";
        $i++;
    } catch(\Exception $e) {}
}

// Ensure lokesh.a is shown if exist
$lokesh = DB::table('superadmins')->where('id', 6212)->first();
if ($lokesh) {
    try {
        $email = CryptService::decryptData($lokesh->email);
        $number = CryptService::decryptData($lokesh->number);
        echo "User Lokesh:\n";
        echo "Email: {$email}\n";
        echo "Phone: {$number}\n";
        echo "-------------------\n";
    } catch(\Exception $e) {}
}
