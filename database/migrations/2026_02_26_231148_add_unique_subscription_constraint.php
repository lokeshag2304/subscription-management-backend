<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ STEP 1 — Remove existing duplicates first, keeping only the earliest row
        // Finds all duplicates by the 5-column combo and deletes all but the lowest id
        DB::statement("
            DELETE s1 FROM subscriptions s1
            INNER JOIN subscriptions s2
            ON  s1.product_id   = s2.product_id
            AND s1.client_id    = s2.client_id
            AND s1.vendor_id    = s2.vendor_id
            AND s1.renewal_date = s2.renewal_date
            AND s1.amount       = s2.amount
            AND s1.id > s2.id
        ");

        // ✅ STEP 2 — Now safe to add the unique constraint
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unique(
                ['product_id', 'client_id', 'vendor_id', 'renewal_date', 'amount'],
                'unique_subscription_record'
            );
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique('unique_subscription_record');
        });
    }
};
