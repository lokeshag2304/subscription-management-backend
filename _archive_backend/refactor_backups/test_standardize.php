<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/ssl', 'POST', [
    'product_id' => '42',
    'client_id' => '1',
    'amount' => '190.00',
    'renewal_date' => '05/20/2030', // mm/dd/yyyy
    'deletion_date' => '',
    'days_to_delete' => '',
    'status' => '1',
    'remarks' => 'Test domain ssl'
]);
$response = $kernel->handle($request);
echo $response->getContent();
