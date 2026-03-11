<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$startDate = null;
$endDate = null;
$applyDateFilter = false;
$scopedClientId = null;

$subQ = DB::table('subscriptions');
if ($scopedClientId !== null) $subQ->where('client_id', $scopedClientId);
if ($applyDateFilter) $subQ->whereBetween('created_at', [$startDate, $endDate]);
$subscriptionCount = $subQ->count();

echo "Subscription count: $subscriptionCount\n";

$sslQ = DB::table('s_s_l_s');
if ($scopedClientId !== null) $sslQ->where('client_id', $scopedClientId);
if ($applyDateFilter) $sslQ->whereBetween('created_at', [$startDate, $endDate]);
$sslCount = $sslQ->count();
echo "SSL count: $sslCount\n";

$hostQ = DB::table('hostings');
if ($scopedClientId !== null) $hostQ->where('client_id', $scopedClientId);
if ($applyDateFilter) $hostQ->whereBetween('created_at', [$startDate, $endDate]);
$hostingCount = $hostQ->count();
echo "Hosting count: $hostingCount\n";

$domQ = DB::table('domain');
if ($scopedClientId !== null) $domQ->where('client_id', $scopedClientId);
if ($applyDateFilter) $domQ->whereBetween('created_at', [$startDate, $endDate]);
$domainsCount = $domQ->count();
echo "Domain count: $domainsCount\n";
