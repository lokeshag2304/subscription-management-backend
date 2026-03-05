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
        $tables = ['domains', 'hostings', 'emails'];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'days_left')) {
                    $table->integer('days_left')->nullable()->after('renewal_date');
                }
                if ($tableName === 'domains' && !Schema::hasColumn($tableName, 'domain_protected')) {
                    $table->boolean('domain_protected')->default(0)->after('remarks');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['domains', 'hostings', 'emails'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'days_left')) {
                    $table->dropColumn('days_left');
                }
                if ($tableName === 'domains' && Schema::hasColumn($tableName, 'domain_protected')) {
                    $table->dropColumn('domain_protected');
                }
            });
        }
    }
};
