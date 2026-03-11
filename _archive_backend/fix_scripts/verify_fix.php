<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SSL;
use App\Services\CryptService;

$record = SSL::orderBy('updated_at', 'desc')->first();

if ($record) {
    echo "ID: " . $record->id . "\n";
    echo "Raw Remarks: " . $record->remarks . "\n";
    
    $decryptedOnce = CryptService::decryptData($record->remarks) ?? $record->remarks;
    echo "Decrypted Once: " . $decryptedOnce . "\n";
    
    $decryptedTwice = CryptService::decryptData($decryptedOnce);
    if ($decryptedTwice && $decryptedTwice !== $decryptedOnce) {
        echo "Decrypted Twice: " . $decryptedTwice . "\n";
    } else {
        echo "Not double encrypted.\n";
    }
} else {
    echo "No SSL records found.\n";
}
