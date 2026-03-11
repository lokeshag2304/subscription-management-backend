<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

$lokesh = DB::table('superadmins')->where('id', 6212)->first();
if ($lokesh) {
    echo "Email: " . CryptService::decryptData($lokesh->email) . "\n";
    echo "Phone: " . CryptService::decryptData($lokesh->number) . "\n";
}
