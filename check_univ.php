<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = ['domains', 'hostings', 'emails', 'counters'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    foreach (DB::select("DESCRIBE $t") as $c) {
        if (in_array($c->Field, ['domain_id', 'vendor_id', 'product_id', 'client_id'])) {
            echo $c->Field . ' NULL=' . $c->Null . PHP_EOL;
        }
    }
}
