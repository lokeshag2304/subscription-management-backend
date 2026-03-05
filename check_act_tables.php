<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

// Check if there are multiple tables
$tables = Illuminate\Support\Facades\DB::select("SHOW TABLES");
$tableKey = array_key_first((array)$tables[0]);
$tbls = array_map(fn($t) => (array)$t, $tables);
$tbls = array_map(fn($t) => array_values($t)[0], $tbls);

// Filter for activity-related ones
$activityTables = array_filter($tbls, fn($t) => str_contains($t, 'activit'));
file_put_contents('activity_tables.txt', implode("\n", $activityTables));

// Also look at categories of the activities table
foreach ($activityTables as $tname) {
    $cols = Illuminate\Support\Facades\Schema::getColumnListing($tname);
    file_put_contents("activity_cols_{$tname}.txt", implode(", ", $cols));
}
echo "done\n";
