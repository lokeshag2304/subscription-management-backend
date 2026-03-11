<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$client = Illuminate\Support\Facades\DB::table('superadmins')->where('login_type', 3)->first();
if (!$client) {
    echo "No client found\n";
    exit;
}

$req = new Illuminate\Http\Request();
$req->setMethod('POST');
$req->headers->set('Content-Type', 'application/json');
$req->initialize([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => $client->id]));

$controller = new App\Http\Controllers\UserManagement();
$resp = $controller->GetClientDetails($req);

file_put_contents("output_client_json.txt", $resp->getContent());
echo "done\n";
