<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\CryptService;

$client = DB::table('superadmins')->where('id', 6209)->first();

$pass = $client->password ?? 'NULL';
echo "Password field: " . substr($pass, 0, 30) . "...\n";
echo "Is bcrypt: " . (str_starts_with($pass, '$2y$') ? 'YES' : 'NO') . "\n";
echo "Length: " . strlen($pass) . "\n";

// d_password
$dpass = '';
try { $dpass = CryptService::decryptData($client->d_password); } catch(\Exception $e) { $dpass = '(decrypt failed)'; }
echo "d_password (decrypted): $dpass\n";

// Now directly re-hash with correct plain text
$plain = 'Rainbow@123';
$newHash = Hash::make($plain);
DB::table('superadmins')->where('id', 6209)->update(['password' => $newHash]);
echo "\nRe-hashed with bcrypt. New hash starts: " . substr($newHash, 0, 20) . "...\n";
echo "Verify check: " . (Hash::check($plain, $newHash) ? 'PASS ✓' : 'FAIL ✗') . "\n";
