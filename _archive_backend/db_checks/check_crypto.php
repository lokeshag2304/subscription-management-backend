<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$v = DB::table('vendors')->first();
if ($v) {
    echo "Vendor Name: " . $v->name . PHP_EOL;
    try {
        echo "Decrypted: " . \App\Services\CryptService::decryptData($v->name) . PHP_EOL;
    } catch (\Exception $e) { }
}

$p = DB::table('products')->first();
if ($p) {
    echo "Product Name: " . $p->name . PHP_EOL;
    try {
        echo "Decrypted: " . \App\Services\CryptService::decryptData($p->name) . PHP_EOL;
    } catch (\Exception $e) { }
}

$c = DB::table('users')->first();
if ($c) {
    echo "Client Name: " . $c->name . PHP_EOL;
    try {
        echo "Decrypted: " . \App\Services\CryptService::decryptData($c->name) . PHP_EOL;
    } catch (\Exception $e) { }
}
