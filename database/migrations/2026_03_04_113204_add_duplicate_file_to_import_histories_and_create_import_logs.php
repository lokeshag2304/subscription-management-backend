<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add duplicate_file column to existing import_histories table
        Schema::table('import_histories', function (Blueprint $table) {
            $table->string('duplicate_file')->nullable()->after('duplicates_count');
            $table->integer('total_rows')->default(0)->after('duplicates_count');
        });

        // 2. Create the new import_logs table (lightweight per-import metadata)
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('module');                         // e.g. 'subscription', 'ssl'
            $table->string('file_name');                     // original uploaded file name
            $table->integer('total_rows')->default(0);
            $table->integer('inserted')->default(0);
            $table->integer('duplicate')->default(0);
            $table->integer('failed')->default(0);
            $table->string('duplicate_file')->nullable();    // path to generated xlsx
            $table->string('imported_by')->default('System');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn(['duplicate_file', 'total_rows']);
        });
        Schema::dropIfExists('import_logs');
    }
};
