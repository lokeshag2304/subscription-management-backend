<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (DB::select('DESCRIBE s_s_l_s') as $c) {
    if ($c->Field === 'domain_id' || $c->Field === 'vendor_id' || $c->Field === 'product_id' || $c->Field === 'client_id') {
        echo $c->Field . ' NULL=' . $c->Null . PHP_EOL;
    }
}
