<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (DB::select('DESCRIBE vendors') as $c) {
    echo $c->Field . ' NULL=' . $c->Null . ' DEFAULT=' . $c->Default . PHP_EOL;
}
