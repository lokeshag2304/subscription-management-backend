<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$columns = array_column(\Illuminate\Support\Facades\DB::select("DESCRIBE import_histories"), 'Field');
echo "Columns: " . implode(', ', $columns) . "\n";
