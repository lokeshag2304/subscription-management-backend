<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$subs = DB::table('subscriptions')->get();
foreach ($subs as $s) {
    echo "ID: {$s->id} | P: {$s->product_id} | V: {$s->vendor_id} | C: {$s->client_id} | R: {$s->renewal_date} | CA: {$s->created_at}\n";
}
