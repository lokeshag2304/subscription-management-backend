<?php
include 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

$tables = ['subscriptions', 's_s_l_s', 'hostings', 'domain', 'emails', 'counters'];
foreach ($tables as $table) {
    echo "Table: $table\n";
    $rows = DB::table($table)->limit(3)->get();
    foreach ($rows as $row) {
        $id = $row->id ?? 'no-id';
        $created = $row->created_at ?? 'NULL';
        $updated = $row->updated_at ?? 'NULL';
        $renewal = $row->renewal_date ?? 'NULL';
        echo " ID: $id | Created: $created | Updated: $updated | Renewal: $renewal\n";
    }
    echo "-------------------\n";
}
