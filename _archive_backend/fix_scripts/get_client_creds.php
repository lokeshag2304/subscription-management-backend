<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

// Get all clients (login_type=3)
$clients = DB::table('superadmins')
    ->where('login_type', 3)
    ->select('id', 'name', 'email', 'number', 'd_password', 'domain_id', 'status', 'created_at')
    ->get();

$out = "=== ALL CLIENTS (login_type=3) ===\n\n";
foreach ($clients as $c) {
    $name  = ''; $email = ''; $phone = ''; $pass = '';
    try { $name  = CryptService::decryptData($c->name); } catch (\Exception $e) { $name = $c->name; }
    try { $email = CryptService::decryptData($c->email); } catch (\Exception $e) { $email = $c->email; }
    try { $phone = CryptService::decryptData($c->number); } catch (\Exception $e) { $phone = $c->number; }
    try { $pass  = CryptService::decryptData($c->d_password); } catch (\Exception $e) { $pass = '(encrypted)'; }

    $domains = [];
    if ($c->domain_id) {
        $domainIds = json_decode($c->domain_id, true) ?? [];
        $domRecs = DB::table('domain')->whereIn('id', $domainIds)->get();
        foreach ($domRecs as $d) {
            try { $domains[] = CryptService::decryptData($d->name); } catch (\Exception $e) { $domains[] = $d->name; }
        }
    }

    $out .= "ID:       {$c->id}\n";
    $out .= "Name:     $name\n";
    $out .= "Email:    $email\n";
    $out .= "Phone:    $phone\n";
    $out .= "Password: $pass\n";
    $out .= "Domains:  " . (empty($domains) ? 'None assigned' : implode(', ', $domains)) . "\n";
    $out .= "Status:   " . ($c->status ? 'Active' : 'Inactive') . "\n";
    $out .= str_repeat('-', 50) . "\n";
}

file_put_contents('all_clients_creds.txt', $out);
echo $out;
