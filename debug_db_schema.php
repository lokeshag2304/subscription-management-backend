<?php
include 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

$output = "";
$tables = ['subscriptions', 's_s_l_s', 'hostings', 'domain', 'emails', 'counters', 'remark_histories'];
foreach ($tables as $table) {
    try {
        $output .= "Table: $table\n";
        $columns = DB::select("DESCRIBE $table");
        foreach ($columns as $col) {
            $output .= "  Field: {$col->Field} | Type: {$col->Type} | Null: {$col->Null}\n";
        }
    } catch (\Exception $e) {
        $output .= "  Error describing $table: " . $e->getMessage() . "\n";
    }
    $output .= "-------------------\n";
}
file_put_contents('c:/xampp/htdocs/SubscriptionBackup/db_schema.txt', $output);
echo "Done\n";
