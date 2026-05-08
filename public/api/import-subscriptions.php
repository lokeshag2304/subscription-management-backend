<?php

include "../config/db.php";

header("Content-Type: application/json");

if(!isset($_FILES['file'])){
    echo json_encode([
        "success"=>false,
        "message"=>"No file uploaded"
    ]);
    exit;
}

$fileName = $_FILES['file']['name'];
$fileTmp = $_FILES['file']['tmp_name'];

$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if($extension !== "csv"){
    echo json_encode([
        "success"=>false,
        "message"=>"Only CSV allowed"
    ]);
    exit;
}

$inserted = 0;
$failed = 0;

if(($handle = fopen($fileTmp, "r")) !== FALSE){
    // Skip header row
    fgetcsv($handle);

    while(($row = fgetcsv($handle, 1000, ",")) !== FALSE){
        if(count($row) < 3){
            $failed++;
            continue;
        }

        $name = trim($row[0]);
        $price = floatval($row[1]);
        $status = trim($row[2]);

        if($name == "" || $price <= 0){
            $failed++;
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO subscriptions
            (name, price, status)
            VALUES (?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                "sds",
                $name,
                $price,
                $status
            );

            if($stmt->execute()){
                $inserted++;
            } else {
                $failed++;
            }
            $stmt->close();
        } else {
            $failed++;
        }
    }

    fclose($handle);
}

echo json_encode([
    "success"=>true,
    "inserted"=>$inserted,
    "failed"=>$failed
]);

exit;
