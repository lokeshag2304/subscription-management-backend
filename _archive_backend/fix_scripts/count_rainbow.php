<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;

$clientId = 6209; // Rainbow Client

$counts = [
    'Subscriptions' => DB::table('subscriptions')->where('client_id', $clientId)->count(),
    'SSL'           => DB::table('s_s_l_s')->where('client_id', $clientId)->count(),
    'Hosting'       => DB::table('hostings')->where('client_id', $clientId)->count(),
    'Domains'       => DB::table('domains')->where('client_id', $clientId)->count(),
    'Emails'        => DB::table('emails')->where('client_id', $clientId)->count(),
    'Counter'       => DB::table('counters')->where('client_id', $clientId)->count(),
];

$out = "=== RAINBOW CLIENT (ID:6209) RECORDS ===\n";
$total = 0;
foreach ($counts as $table => $count) {
    $out .= "  $table: $count record(s)\n";
    $total += $count;
}
$out .= "  TOTAL: $total\n";

file_put_contents('rainbow_records.txt', $out);
echo $out;
