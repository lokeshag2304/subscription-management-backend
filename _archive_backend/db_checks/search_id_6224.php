<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$tables = ['users', 'superadmins', 'user_management'];
foreach($tables as $t) {
    $count = DB::table($t)->where('id', 6224)->count();
    echo "TABLE $t: $count\n";
}
