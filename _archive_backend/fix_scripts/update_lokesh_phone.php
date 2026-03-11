<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

$targetPhone = '9154151265';
$targetEncrypted = CryptService::encryptData($targetPhone);
$lokeshEmailEncrypted = CryptService::encryptData('lokesh.a@flyingstars.biz');

// Check if anyone has the number already
$existingUser = DB::table('superadmins')
    ->where(function($q) use ($targetPhone, $targetEncrypted) {
        $q->where('number', $targetPhone)
          ->orWhere('number', $targetEncrypted);
    })->first();

if ($existingUser) {
    if ($existingUser->email === $lokeshEmailEncrypted || CryptService::decryptData($existingUser->email) === 'lokesh.a@flyingstars.biz') {
         echo "Phone already belongs to Lokesh.\n";
    } else {
         echo "Found phone for user ID: " . $existingUser->id . " Email: " . CryptService::decryptData($existingUser->email) . "\nUpdating Lokesh's phone anyway...\n";
    }
}

// Update Lokesh's phone number
$updated = DB::table('superadmins')
    ->where('email', $lokeshEmailEncrypted)
    ->update(['number' => $targetEncrypted]);

if ($updated) {
    echo "Successfully updated lokesh.a@flyingstars.biz phone to $targetPhone\n";
} else {
    // If not updated, verify Lokesh's id and try direct update
    $updatedLokesh = DB::table('superadmins')->where('id', 6212)->update(['number' => $targetEncrypted]);
    if ($updatedLokesh) {
        echo "Successfully updated lokesh's phone to $targetPhone via ID.\n";
    } else {
        echo "Failed to update Lokesh's phone.\n";
    }
}
