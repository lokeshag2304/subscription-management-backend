<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user_id = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
    'name' => 'baba', 
    'email' => 'baba@baba.com', 
    'password' => '123'
]);

$prod_id = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
    'name' => 'baba product'
]);

$vend_id = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId([
    'name' => 'baba vendor'
]);

$sub_id = \Illuminate\Support\Facades\DB::table('subscriptions')->insertGetId([
    'product_id' => $prod_id,
    'client_id' => $user_id,
    'vendor_id' => $vend_id,
    'amount' => 1500,
    'renewal_date' => '2026-10-01',
    'status' => 1
]);

echo "Created sub ID: " . $sub_id . "\n";
