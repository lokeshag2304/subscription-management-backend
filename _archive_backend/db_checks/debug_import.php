<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

echo "=== ALL PRODUCTS ===\n";
$products = DB::table('products')->select('id', 'name')->get();
foreach ($products as $p) {
    try { $name = CryptService::decryptData($p->name); }
    catch (Exception $e) { $name = $p->name; }
    echo "ID: {$p->id} => {$name}\n";
}

echo "\n=== ALL VENDORS ===\n";
$vendors = DB::table('vendors')->select('id', 'name')->get();
foreach ($vendors as $v) {
    try { $name = CryptService::decryptData($v->name); }
    catch (Exception $e) { $name = $v->name; }
    echo "ID: {$v->id} => {$name}\n";
}

echo "\n=== ALL CLIENTS (superadmins) ===\n";
$clients = DB::table('superadmins')->select('id', 'name')->get();
foreach ($clients as $c) {
    try { $name = CryptService::decryptData($c->name); }
    catch (Exception $e) { $name = $c->name; }
    echo "ID: {$c->id} => {$name}\n";
}
