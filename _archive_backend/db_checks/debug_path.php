<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$h = DB::table('import_histories')->latest()->first();
echo "ID: " . $h->id . "\n";
echo "PATH: " . $h->file_path . "\n";
echo "NAME: " . $h->file_name . "\n";
echo "SUCCESS: " . $h->successful_rows . "\n";
echo "EXISTS: " . (Illuminate\Support\Facades\Storage::exists($h->file_path) ? 'YES' : 'NO') . "\n";
echo "DISK: " . config('filesystems.default') . "\n";
