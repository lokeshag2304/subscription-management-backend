<?php

// Load database configuration dynamically from the Laravel .env file
$envPath = __DIR__ . '/../../.env';
$env = [];
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? 3306;
$db = $env['DB_DATABASE'] ?? 'fsisubscriptiondb';
$user = $env['DB_USERNAME'] ?? 'fsisubscriptionuser';
$pass = $env['DB_PASSWORD'] ?? 'fK32rykJTB43ad3riJ63';

$conn = new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}
