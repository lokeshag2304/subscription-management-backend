<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$output = "";
$tables = ['subscriptions', 's_s_l_s', 'counters', 'domains', 'hostings', 'emails'];
foreach ($tables as $t) {
    if (Illuminate\Support\Facades\Schema::hasTable($t)) {
        $output .= $t . ":\n";
        $columns = Illuminate\Support\Facades\Schema::getColumnListing($t);
        $output .= implode(', ', $columns) . "\n\n";
    }
}
file_put_contents('tables_cols.txt', $output);
