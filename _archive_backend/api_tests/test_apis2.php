<?php
try {
	$app = require_once __DIR__ . '/bootstrap/app.php';
	$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

	$request = Illuminate\Http\Request::create('/api/domains', 'GET');
	$response = $kernel->handle($request);
	echo $response->getContent() . "\n";
} catch (\Exception $e) {
	echo "Exception: " . $e->getMessage() . "\n";
}
