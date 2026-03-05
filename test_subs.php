<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$table = 'subscriptions';
$columns = Schema::getColumnListing($table);
echo "Columns in $table: " . implode(', ', $columns) . "\n";

$count = DB::table($table)->only(['id'])->count();
echo "Total count: $count\n\n";

$first = DB::table($table)->first();
if ($first) {
    echo "ID: " . $first->id . "\n";
    echo "CLIENT_ID: " . ($first->client_id ?? 'N/A') . "\n";
    echo "PRODUCT_ID: " . ($first->product_id ?? 'N/A') . "\n";
    echo "VENDOR_ID: " . ($first->vendor_id ?? 'N/A') . "\n";
}
