<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== import_logs ===\n";
foreach (DB::select('DESCRIBE import_logs') as $c) {
    echo "{$c->Field} ({$c->Type}) null={$c->Null} default={$c->Default}\n";
}

echo "\n=== import_histories ===\n";
foreach (DB::select('DESCRIBE import_histories') as $c) {
    echo "{$c->Field} ({$c->Type}) null={$c->Null} default={$c->Default}\n";
}
