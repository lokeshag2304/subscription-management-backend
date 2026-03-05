<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            // Only add columns that are missing — safe to re-run
            if (!Schema::hasColumn('import_logs', 'imported_by')) {
                $table->string('imported_by')->default('System')->after('duplicate_file');
            }
            if (!Schema::hasColumn('import_logs', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('imported_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('import_logs', 'imported_by')) $cols[] = 'imported_by';
            if (Schema::hasColumn('import_logs', 'client_id'))   $cols[] = 'client_id';
            if (!empty($cols)) $table->dropColumn($cols);
        });
    }
};
