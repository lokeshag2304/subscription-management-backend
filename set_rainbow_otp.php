<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;

// Set fixed OTP = 1234 for Rainbow Client (id=6209), valid for 24 hours
DB::table('superadmins')
    ->where('id', 6209)
    ->update([
        'otp'        => '1234',
        'otp_expiry' => \Carbon\Carbon::now()->addHours(24),
    ]);

echo "OTP set: 1234\n";
echo "Valid until: " . \Carbon\Carbon::now()->addHours(24)->format('Y-m-d H:i:s') . "\n";
echo "User: Rainbow Client (rainbowcroprise@gmail.com)\n";
