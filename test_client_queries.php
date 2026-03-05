<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function getCountAndRecentFromTables($clientId) {
    $tables = [
        1 => ['name' => 'subscriptions', 'label' => 'Subscriptions'],
        2 => ['name' => 's_s_l_s', 'label' => 'SSL'],
        3 => ['name' => 'hostings', 'label' => 'Hosting'],
        4 => ['name' => 'domains', 'label' => 'Domains'],
        5 => ['name' => 'emails', 'label' => 'Emails'],
        6 => ['name' => 'counters', 'label' => 'Counter'],
    ];

    $typeCounts = [];
    $recentCategories = [];

    foreach ($tables as $typeId => $info) {
        $q = \Illuminate\Support\Facades\DB::table($info['name'])->where('client_id', $clientId);
        $count = $q->count();
        
        $typeCounts[] = [
            'type_id' => $typeId,
            'type_name' => $info['label'],
            'count' => $count
        ];
        
        $recentRows = $q->orderBy('id', 'desc')->take(10)->get();
        foreach ($recentRows as $row) {
            $recentCategories[] = [
                'id' => $row->id,
                'record_type' => $info['label'],
                'status' => (isset($row->status) && $row->status == 1) ? 'Active' : 'Deactive',
                'created_at' => $row->created_at,
                // Add mapping logic if needed
            ];
        }
    }
    
    return ['counts' => $typeCounts, 'recent' => $recentCategories];
}

$counts = getCountAndRecentFromTables(6193); // Test with Rainbow Client
print_r($counts);
