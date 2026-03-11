<?php
$ch = curl_init('http://127.0.0.1:8000/api/secure/Activites/Get-acitivites');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['admin_id' => 291, 'page' => 1, 'rowsPerPage' => 10, 'order' => 'desc', 'orderBy' => 'id', 'search' => '']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
echo "HTTP: $code | Total: " . ($data['total'] ?? 'N/A') . " | Rows: " . count($data['rows'] ?? []) . "\n";
foreach ($data['rows'] ?? [] as $r) {
    echo "  [{$r['id']}] {$r['action']} — {$r['message']}\n";
}
