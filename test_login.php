<?php
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email'    => 'testuser@flyingstars.local',
    'password' => 'Test@1234'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
file_put_contents('test_login_result.txt',
    "HTTP: $code\n" .
    "Status: " . ($data['status'] ? 'true' : 'false') . "\n" .
    "Message: " . ($data['message'] ?? 'N/A') . "\n" .
    "Role: " . ($data['role'] ?? 'N/A') . "\n" .
    "login_type: " . ($data['login_type'] ?? 'N/A') . "\n" .
    "admin_id: " . ($data['admin_id'] ?? 'N/A') . "\n" .
    "name: " . ($data['name'] ?? 'N/A') . "\n"
);
echo "done\n";
