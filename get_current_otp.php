<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

use Illuminate\Support\Facades\DB;

$client = DB::table('superadmins')->where('id', 6209)->select('otp', 'otp_expiry')->first();
echo "Current OTP: " . $client->otp . "\n";
echo "Expires: " . $client->otp_expiry . "\n";
