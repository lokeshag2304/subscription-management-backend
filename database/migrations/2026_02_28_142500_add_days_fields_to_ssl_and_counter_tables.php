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
        foreach (['s_s_l_s', 'counters'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'deletion_date')) {
                    $table->date('deletion_date')->nullable()->after('renewal_date');
                }
                if (!Schema::hasColumn($tableName, 'days_left')) {
                    $table->integer('days_left')->nullable()->after('renewal_date');
                }
                if (!Schema::hasColumn($tableName, 'days_to_delete')) {
                    $table->integer('days_to_delete')->nullable()->after('deletion_date');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['s_s_l_s', 'counters'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropColumn(['deletion_date', 'days_left', 'days_to_delete']);
            });
        }
    }
};
