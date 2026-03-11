<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$logs = DB::table('activity_logs')->orderBy('id', 'desc')->limit(10)->get();
foreach ($logs as $log) {
    echo "ID: {$log->id}, User: {$log->user_name}, Raw: " . bin2hex($log->user_name) . "\n";
}
