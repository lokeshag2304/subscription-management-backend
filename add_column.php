<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    if (!Schema::hasColumn('import_histories', 'data_snapshot')) {
        DB::statement('ALTER TABLE import_histories ADD COLUMN data_snapshot LONGTEXT NULL AFTER duplicates_count');
        echo "Column data_snapshot added successfully.\n";
    } else {
        echo "Column data_snapshot already exists.\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
