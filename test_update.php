<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$id = 2; // Testing for record with ID 2 as it's in the screenshot
$request = Illuminate\Http\Request::create(
    "/api/secure/subscriptions/{$id}", 'PUT', [], [], [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
    json_encode(['remarks' => 'Changes made', 's_id' => 1]) // Using s_id 1
);

$response = $kernel->handle($request);
echo "RESPONSE STATUS: " . $response->getStatusCode() . "\n";
echo "RESPONSE BODY: " . $response->getContent() . "\n";
