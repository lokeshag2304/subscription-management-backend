<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Http\Request;
use App\Http\Controllers\SSLController;

// Mock request
$req = new Request();
$req->setMethod('POST');
$req->merge([
    'domain_id' => 1,
    'product_id' => 1,
    'client_id' => 1,
    'vendor_id' => 1,
    'amount' => 12.34,
    'renewal_date' => '2026-03-07',
    'deletion_date' => '2026-03-13',
    'status' => 1,
    'remarks' => 'test mock SSL'
]);

$controller = new SSLController();
try {
    $resp = $controller->store($req);
    echo $resp->getContent();
} catch (\Exception $e) {
    echo $e->getMessage() . "\n" . $e->getTraceAsString();
}
