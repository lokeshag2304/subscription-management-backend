<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tables = ['subscriptions', 's_s_l_s', 'hostings', 'domains', 'emails', 'counters'];

$out = "";
foreach ($tables as $t) {
    try {
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing($t);
        $out .= str_pad($t, 15) . ": client_id=" . (in_array('client_id', $cols) ? "YES" : "NO") . ", domain_id=" . (in_array('domain_id', $cols) ? "YES" : "NO") . "\n";
    } catch (\Exception $e) {}
}
file_put_contents("schema_out.txt", $out);
