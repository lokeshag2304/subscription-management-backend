<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$oldRecords = DB::table('domain')->get();
$count = 0;

foreach ($oldRecords as $old) {
    // 1. Create or Find Product
    $productId = DB::table('products')->insertGetId([
        'name' => $old->name,
        'created_at' => $old->created_at
    ]);

    // 2. Insert into the new Standardized table
    try {
        DB::table('domains')->insert([
            'product_id'   => $productId,
            'client_id'    => 1, // Default to first client
            'vendor_id'    => 1, // Default to first vendor
            'amount'       => 0,
            'renewal_date' => date('Y-m-d', strtotime($old->created_at . ' + 1 year')),
            'status'       => 1,
            'created_at'   => $old->created_at,
            'updated_at'   => now()
        ]);
        $count++;
    } catch (\Exception $e) {
        // Skip duplicates
    }
}

echo "Successfully migrated $count domain records.";
