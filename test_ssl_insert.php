<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/ssl', 'POST', [
    'product' => '',
    'client' => 'TestClient',
    'vendor' => '',
    'amount' => '150.00',
    'renewal_date' => '05/20/2026',
    'deletion_date' => '',
    'days_to_delete' => '',
    'status' => '1',
    'remarks' => 'hello world'
]);
$response = $kernel->handle($request);
echo $response->getContent();
