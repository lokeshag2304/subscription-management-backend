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
        if (Schema::hasTable('superadmins')) {
            Schema::table('superadmins', function (Blueprint $table) {
                if (!Schema::hasColumn('superadmins', 'otp_enabled')) {
                    $table->boolean('otp_enabled')->default(true)->after('status');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'otp_enabled')) {
                    $table->boolean('otp_enabled')->default(true)->after('password');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('superadmins', function (Blueprint $table) {
            $table->dropColumn('otp_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('otp_enabled');
        });
    }
};
