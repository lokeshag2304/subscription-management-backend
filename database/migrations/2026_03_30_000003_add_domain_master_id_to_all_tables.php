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
        // 1. domains (plural) table: add domain_master_id, drop name
        if (Schema::hasTable('domains')) {
            Schema::table('domains', function (Blueprint $table) {
                if (!Schema::hasColumn('domains', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('id');
                }
            });
        }

        // 2. s_s_l_s table: add domain_master_id
        if (Schema::hasTable('s_s_l_s')) {
            Schema::table('s_s_l_s', function (Blueprint $table) {
                if (!Schema::hasColumn('s_s_l_s', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('domain_id');
                }
            });
        }

        // 3. hostings table: add domain_master_id
        if (Schema::hasTable('hostings')) {
            Schema::table('hostings', function (Blueprint $table) {
                if (!Schema::hasColumn('hostings', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('domain_id');
                }
            });
        }

        // 4. subscriptions table: add domain_master_id
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (!Schema::hasColumn('subscriptions', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('id');
                }
            });
        }

        // 5. emails table: add domain_master_id
        if (Schema::hasTable('emails')) {
            Schema::table('emails', function (Blueprint $table) {
                if (!Schema::hasColumn('emails', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('domain_id');
                }
            });
        }

        // 6. counters table: add domain_master_id
        if (Schema::hasTable('counters')) {
            Schema::table('counters', function (Blueprint $table) {
                if (!Schema::hasColumn('counters', 'domain_master_id')) {
                    $table->unsignedBigInteger('domain_master_id')->nullable()->after('domain_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse if needed, but given the user's specific request, they want forward progress.
    }
};
