<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['hostings', 's_s_l_s', 'domains', 'emails', 'counters'];

        foreach ($tables as $table) {
            // Only add if columns exist (SSL/Counter might not have them yet)
            if (Schema::hasColumns($table, ['product_id', 'client_id', 'amount', 'renewal_date'])) {
                
                // 1. Clean existing duplicates
                DB::statement("
                    DELETE s1 FROM {$table} s1
                    INNER JOIN {$table} s2
                    ON  s1.product_id   = s2.product_id
                    AND s1.client_id    = s2.client_id
                    AND s1.amount       = s2.amount
                    AND s1.renewal_date = s2.renewal_date
                    AND s1.id > s2.id
                ");

                // 2. Drop existing if any, then add
                $this->dropIndexIfExists($table, 'unique_business_record');

                Schema::table($table, function (Blueprint $tableObj) {
                    $tableObj->unique(
                        ['product_id', 'client_id', 'amount', 'renewal_date'],
                        'unique_business_record'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['hostings', 's_s_l_s', 'domains', 'emails', 'counters'];
        foreach ($tables as $table) {
            $this->dropIndexIfExists($table, 'unique_business_record');
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::select("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
        ", [$table, $index]);

        if (!empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }
};
