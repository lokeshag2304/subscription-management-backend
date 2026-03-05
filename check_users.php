<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = Illuminate\Support\Facades\DB::table('users')->first();
if ($user) {
    echo "ID: " . $user->id . "\n";
    echo "Name: " . $user->name . "\n";
} else { echo "No users\n"; }

$sadmin = Illuminate\Support\Facades\DB::table('superadmins')->where('login_type', 3)->first();
if ($sadmin) {
    echo "Sadmin ID: " . $sadmin->id . "\n";
    echo "Sadmin Name: " . $sadmin->name . "\n";
} else { echo "No sadmins\n"; }
