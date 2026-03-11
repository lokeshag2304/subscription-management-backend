<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();

// Seed a test activity via ActivityLogger
\App\Services\ActivityLogger::added(291, 'Subscription', 'Product: TestPlan | Client: Bablu Parmar | Amount: 1200 | Renewal: 2026-06-01');
\App\Services\ActivityLogger::updated(291, 'SSL', 'Domain: example.com | Client: Rainbow Client | Renewal: 2026-12-31');
\App\Services\ActivityLogger::deleted(291, 'Hosting', 'Domain: test.com | Client: Live Account | Renewal: 2025-01-15');

// Now fetch using the same logic as the controller
$activities = Illuminate\Support\Facades\DB::table('activities as a')
    ->leftJoin('superadmins as sa', 'a.user_id', '=', 'sa.id')
    ->select(
        'a.*',
        'sa.name',
        'sa.login_type',
        Illuminate\Support\Facades\DB::raw("CASE 
            WHEN sa.login_type = 1 THEN 'Superadmin' 
            WHEN sa.login_type = 2 THEN 'User' 
            WHEN sa.login_type = 3 THEN 'Client' 
            ELSE 'Unknown' 
        END AS role")
    )
    ->orderBy('a.id', 'desc')
    ->limit(5)
    ->get()
    ->map(function ($item) {
        $creatorName = null;
        try { $creatorName = $item->name ? \App\Services\CryptService::decryptData($item->name) : null; } catch (\Exception $e) {}
        return [
            'id'           => $item->id,
            'user_id'      => $item->user_id,
            'action'       => \App\Services\CryptService::decryptData($item->action),
            'message'      => \App\Services\CryptService::decryptData($item->message),
            's_action'     => $item->s_action,
            's_message'    => $item->s_message,
            'creator_name' => $creatorName,
            'role'         => $item->role,
            'created_at'   => \Carbon\Carbon::parse($item->created_at)->format('M d, Y h:i A'),
        ];
    });

file_put_contents('test_activities_output.json', json_encode($activities, JSON_PRETTY_PRINT));
echo "Done. Saved " . count((array)$activities) . " records.\n";
