<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "domains columns: " . implode(', ', Illuminate\Support\Facades\Schema::getColumnListing('domains')) . "\n";
echo "domain columns: " . implode(', ', Illuminate\Support\Facades\Schema::getColumnListing('domain')) . "\n";
