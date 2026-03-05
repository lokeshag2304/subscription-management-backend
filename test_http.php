<?php
$url = 'http://127.0.0.1:8000/api/secure/Usermanagement/get-clients-user-list';

// Test clients list (type=1 → login_type=3)
$payload = json_encode(['type' => 1, 'page' => 0, 'rowsPerPage' => 10, 'search' => '', 'order' => 'desc', 'orderBy' => 'id']);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($resp, true);
file_put_contents("test_clients_result.txt",
    "CLIENTS HTTP: $code | Total: " . ($data['total'] ?? 'N/A') . " | Rows: " . count($data['rows'] ?? []) . "\n"
    . implode("\n", array_map(fn($r) => "  [{$r['id']}] {$r['name']} | {$r['email']}", $data['rows'] ?? []))
);

// Test users list (type=2 → login_type=2)
$payload2 = json_encode(['type' => 2, 'page' => 0, 'rowsPerPage' => 10, 'search' => '', 'order' => 'desc', 'orderBy' => 'id']);
$ch2 = curl_init($url);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload2);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
$data2 = json_decode($resp2, true);
file_put_contents("test_users_result.txt",
    "USERS HTTP: $code2 | Total: " . ($data2['total'] ?? 'N/A') . " | Rows: " . count($data2['rows'] ?? []) . "\n"
    . implode("\n", array_map(fn($r) => "  [{$r['id']}] {$r['name']} | {$r['email']}", $data2['rows'] ?? []))
);
echo "Done\n";
