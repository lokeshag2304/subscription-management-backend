<?php
include 'vendor/autoload.php';
$app = include_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$history = \App\Models\ImportHistory::orderBy('created_at', 'desc')->take(5)->get();
foreach ($history as $h) {
    echo "ID: {$h->id} | Duplicates: {$h->duplicates_count} | File: " . ($h->duplicate_file ?: 'NULL') . "\n";
}
