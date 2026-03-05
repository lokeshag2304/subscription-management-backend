<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$counters = \Illuminate\Support\Facades\DB::table('counters')->get();
foreach ($counters as $c) {
    echo "ID: " . $c->id . " | Amount: " . $c->amount . " | Renewal: [" . trim($c->renewal_date) . "] | RAW: [" . ($c->renewal_date) . "]\n";
}
