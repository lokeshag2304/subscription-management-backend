<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::create(['name' => 'Test User', 'email' => 'test@test.com', 'password' => '1234']);
$prod = \App\Models\Product::create(['name' => 'Test Prod']);
$vend = \App\Models\Vendor::create(['name' => 'Test Vendor']);

$sub = \App\Models\Subscription::create([
    'product_id' => $prod->id,
    'client_id' => $user->id,
    'vendor_id' => $vend->id,
    'amount' => 1500,
    'renewal_date' => '2026-10-01',
    'status' => 1
]);

echo "Created subscription with ID: " . $sub->id . " - " . $user->name . "\n";
