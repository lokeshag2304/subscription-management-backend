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
        $tables = [
            'subscriptions',
            's_s_l_s',
            'hostings',
            'domains',
            'emails',
            'counters',
            'tools',
            'user_management'
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'grace_period')) {
                    $table->integer('grace_period')->default(0);
                }
                if (!Schema::hasColumn($tableName, 'due_date')) {
                    $table->datetime('due_date')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'subscriptions',
            's_s_l_s',
            'hostings',
            'domains',
            'emails',
            'counters',
            'tools',
            'user_management'
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['grace_period', 'due_date']);
            });
        }
    }
};
