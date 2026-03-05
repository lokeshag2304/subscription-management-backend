<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix client_id to be a loose integer (no FK) so superadmin IDs work.
     * The original migrations pointed client_id -> users, but clients are in superadmins.
     */
    public function up(): void
    {
        // Tables that have client_id FK pointing to wrong table
        $tables = ['subscriptions', 'domains', 'hostings', 'emails'];

        foreach ($tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            // Drop the foreign key constraint first (MySQL)
            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    // Laravel conventional FK name: tablename_client_id_foreign
                    $fkName = "{$tableName}_client_id_foreign";
                    $table->dropForeign($fkName);
                });
            } catch (\Throwable $e) {
                // If FK doesn't exist by that name, try raw SQL
                try {
                    $fks = DB::select("
                        SELECT CONSTRAINT_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = ?
                          AND COLUMN_NAME = 'client_id'
                          AND REFERENCED_TABLE_NAME IS NOT NULL
                    ", [$tableName]);

                    foreach ($fks as $fk) {
                        DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                    }
                } catch (\Throwable $e2) {
                    // Already gone or doesn't exist
                }
            }

            // Now alter client_id to be a regular nullable unsigned bigint (no FK)
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('client_id')->nullable()->change();
            });
        }

        // Also fix subscriptions table specifically (it had non-nullable FK)
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('client_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Cannot restore FK safely, no-op
    }
};
