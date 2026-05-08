<?php

include "../config/db.php";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="subscriptions.csv"');

$output = fopen("php://output", "w");

fputcsv($output, [
    "name",
    "price",
    "status"
]);

$result = $conn->query("
    SELECT name, price, status
    FROM subscriptions
    ORDER BY id DESC
");

if ($result) {
    while($row = $result->fetch_assoc()){
        fputcsv($output, [
            $row["name"],
            $row["price"],
            $row["status"]
        ]);
    }
}

fclose($output);
exit;
