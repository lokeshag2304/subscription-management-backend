<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = DB::table('users')->where('id', 6224)->first();
if ($u) {
    echo "USER FOUND. NAME: " . ($u->name ?? 'NULL_ATTR') . "\n";
    print_r($u);
} else {
    echo "USER NOT FOUND.\n";
}
