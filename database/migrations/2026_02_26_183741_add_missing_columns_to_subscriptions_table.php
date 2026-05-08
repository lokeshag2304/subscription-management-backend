<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'days_left')) {
                $table->integer('days_left')->nullable()->after('renewal_date');
            }
            if (!Schema::hasColumn('subscriptions', 'days_to_delete')) {
                $table->integer('days_to_delete')->nullable()->after('deletion_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'days_left')) {
                $table->dropColumn('days_left');
            }
            if (Schema::hasColumn('subscriptions', 'days_to_delete')) {
                $table->dropColumn('days_to_delete');
            }
        });
    }
};
