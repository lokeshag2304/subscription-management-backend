<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SSL;
use App\Services\CryptService;

$record = SSL::latest()->first(); // Probable ID 13 or similar

echo "ID: " . $record->id . "\n";
echo "Raw Remarks: " . $record->remarks . "\n";
$dec1 = CryptService::decryptData($record->remarks);
echo "Decrypted 1: " . $dec1 . "\n";
$dec2 = CryptService::decryptData($dec1);
echo "Decrypted 2: " . $dec2 . "\n";

if ($dec1 !== $record->remarks) {
    echo "Decryption 1 succeeded.\n";
} else {
    echo "Decryption 1 failed.\n";
}
