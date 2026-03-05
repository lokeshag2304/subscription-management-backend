<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sub = \App\Models\Subscription::create([
    'product_id' => 3, // Assuming 3 is a valid product
    'client_id' => 6193, // Superadmin with login 3? wait users is empty! No, but wait...
    'vendor_id' => 1,
    'amount' => 1500,
    'renewal_date' => '2026-10-01',
    'status' => 1
]);

echo "Created subscription with ID: " . $sub->id . "\n";
