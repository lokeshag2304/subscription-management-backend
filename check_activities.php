<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$k = $app->make(Illuminate\Contracts\Console\Kernel::class);
$k->bootstrap();
$cols = Illuminate\Support\Facades\Schema::getColumnListing('activities');
file_put_contents('activities_info.txt',
    "activities columns: " . implode(', ', $cols) . "\n"
);

// Also check recent activities
$rows = Illuminate\Support\Facades\DB::table('activities')->orderBy('id','desc')->take(3)->get();
$out = "";
foreach ($rows as $r) {
    $out .= "  [{$r->id}] action={$r->action} | user_id={$r->user_id} | created_at={$r->created_at}\n";
}
file_put_contents('activities_rows.txt', $out);
