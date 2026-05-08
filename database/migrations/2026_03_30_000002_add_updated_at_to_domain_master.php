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
        if (Schema::hasTable('domain_master')) {
            Schema::table('domain_master', function (Blueprint $table) {
                if (!Schema::hasColumn('domain_master', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('domain_master')) {
            Schema::table('domain_master', function (Blueprint $table) {
                if (Schema::hasColumn('domain_master', 'updated_at')) {
                    $table->dropColumn('updated_at');
                }
            });
        }
    }
};
