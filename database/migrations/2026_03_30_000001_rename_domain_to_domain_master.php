<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Rename table domain → domain_master
        Schema::rename('domain', 'domain_master');

        // Step 2: Add domain_name column (mirrors 'name') and timestamps if missing
        Schema::table('domain_master', function (Blueprint $table) {
            // Add domain_name as a proper column (the table previously used 'name')
            if (!Schema::hasColumn('domain_master', 'domain_name')) {
                $table->string('domain_name')->nullable()->after('id');
            }
            // Ensure timestamps exist
            if (!Schema::hasColumn('domain_master', 'created_at')) {
                $table->timestamps();
            }
        });

        // Step 3: Copy existing 'name' values into 'domain_name'
        DB::statement('UPDATE domain_master SET domain_name = name WHERE domain_name IS NULL');
    }

    public function down(): void
    {
        Schema::table('domain_master', function (Blueprint $table) {
            if (Schema::hasColumn('domain_master', 'domain_name')) {
                $table->dropColumn('domain_name');
            }
        });

        Schema::rename('domain_master', 'domain');
    }
};
