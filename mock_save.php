<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use Illuminate\Http\Request;
use App\Http\Controllers\DomainController;

// Mock request
$req = new Request();
$req->setMethod('POST');
$req->merge([
    'product_id' => 46,
    'client_id' => 1,
    'vendor_id' => 1,
    'renewal_date' => '2025-03-06',
    'deletion_date' => '2025-03-12',
    'domain_protected' => 1,
    'remarks' => 'test mock'
]);

$controller = new DomainController();
try {
    $resp = $controller->store($req);
    echo $resp->getContent();
} catch (\Exception $e) {
    echo $e->getMessage();
}
