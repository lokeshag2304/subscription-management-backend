<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Support\Facades\Schema;
$cols = Schema::getColumnListing('domains');
asort($cols);
foreach ($cols as $col) echo $col . "\n";
