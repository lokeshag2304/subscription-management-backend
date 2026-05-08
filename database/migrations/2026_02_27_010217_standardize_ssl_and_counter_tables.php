<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['s_s_l_s', 'counters'] as $table) {
            Schema::table($table, function (Blueprint $tableObj) {
                if (!Schema::hasColumn($tableObj->getTable(), 'product_id')) {
                    $tableObj->integer('product_id')->after('id')->unsigned()->nullable();
                }
                if (!Schema::hasColumn($tableObj->getTable(), 'client_id')) {
                    $tableObj->bigInteger('client_id')->after('product_id')->unsigned()->nullable();
                }
                if (!Schema::hasColumn($tableObj->getTable(), 'vendor_id')) {
                    $tableObj->integer('vendor_id')->after('client_id')->unsigned()->nullable();
                }
                if (!Schema::hasColumn($tableObj->getTable(), 'renewal_date')) {
                    $tableObj->date('renewal_date')->after('amount')->nullable();
                }
            });

            // If start_date/expiry_date exists, copy to renewal_date (temporary)
            if (Schema::hasColumn($table, 'expiry_date')) {
                DB::statement("UPDATE {$table} SET renewal_date = expiry_date WHERE renewal_date IS NULL");
            }
        }
    }

    public function down(): void
    {
        foreach (['s_s_l_s', 'counters'] as $table) {
            Schema::table($table, function (Blueprint $tableObj) {
                $tableObj->dropColumn(['product_id', 'client_id', 'vendor_id', 'renewal_date']);
            });
        }
    }
};
