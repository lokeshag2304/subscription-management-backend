<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req1 = new Illuminate\Http\Request();
$req1->setMethod('POST');
$req1->headers->set('Content-Type', 'application/json');
$req1->initialize([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['type' => 1, 'page' => 0, 'rowsPerPage' => 10, 'search' => '', 'order' => 'desc', 'orderBy' => 'id']));

$controller = new App\Http\Controllers\UserManagement();
$resp1 = $controller->list($req1);
file_put_contents("output_list.json", $resp1->getContent());
echo "saved output_list.json\n";
