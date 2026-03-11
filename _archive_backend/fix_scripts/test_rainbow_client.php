<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

// Test login for Rainbow Client via HTTP
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email'    => 'rainbowcroprise@gmail.com',
    'password' => 'Rainbow@123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$loginResp = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$login = json_decode($loginResp, true);

// Also count their records
$clientId = 6209;
$counts = [
    'Subscriptions' => DB::table('subscriptions')->where('client_id', $clientId)->count(),
    'SSL'           => DB::table('s_s_l_s')->where('client_id', $clientId)->count(),
    'Hosting'       => DB::table('hostings')->where('client_id', $clientId)->count(),
    'Domains'       => DB::table('domains')->where('client_id', $clientId)->count(),
    'Emails'        => DB::table('emails')->where('client_id', $clientId)->count(),
    'Counter'       => DB::table('counters')->where('client_id', $clientId)->count(),
];

$out = "=== RAINBOW CLIENT LOGIN TEST ===\n";
$out .= "HTTP: $loginCode\n";
$out .= "Status: " . ($login['status'] ? 'SUCCESS' : 'FAILED') . "\n";
$out .= "Message: " . ($login['message'] ?? '') . "\n";
$out .= "Role: " . ($login['role'] ?? '') . "\n";
$out .= "login_type: " . ($login['login_type'] ?? '') . "\n";
$out .= "admin_id: " . ($login['admin_id'] ?? '') . "\n\n";
$out .= "=== RAINBOW CLIENT RECORDS ===\n";
foreach ($counts as $table => $count) {
    $out .= "  $table: $count record(s)\n";
}

file_put_contents('rainbow_client_test.txt', $out);
echo $out;
