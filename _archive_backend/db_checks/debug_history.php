<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "--- RECENT HISTORY ---\n";
$history = DB::table('import_histories')->latest()->take(5)->get();
print_r($history);

echo "\n--- FILES IN STORAGE ---\n";
$files = glob('storage/app/imports/*');
print_r($files);

echo "\n--- STORAGE CONFIG ---\n";
echo "Default: " . config('filesystems.default') . "\n";
print_r(config('filesystems.disks.local'));
