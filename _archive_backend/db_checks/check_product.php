<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$p = Illuminate\Support\Facades\DB::table('products')->first();
if ($p) {
    echo "ID: " . $p->id . "\n";
    echo "Name raw: " . $p->name . "\n";
    try {
        echo "Name dec: " . \App\Services\CryptService::decryptData($p->name) . "\n";
    } catch (\Exception $e) { echo "Err dec\n"; }
} else { echo "No products\n"; }
