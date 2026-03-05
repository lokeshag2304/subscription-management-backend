<?php
include 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

$tables = ['subscriptions', 's_s_l_s', 'hostings', 'domain', 'emails', 'counters'];
foreach ($tables as $table) {
    $row = DB::table($table)->first();
    if ($row) {
        echo "Table: $table\n";
        echo "created_at: " . ($row->created_at ?? 'NULL') . "\n";
        echo "updated_at: " . ($row->updated_at ?? 'NULL') . "\n";
        if (isset($row->renewal_date)) echo "renewal_date: " . $row->renewal_date . "\n";
        echo "-------------------\n";
    }
}
