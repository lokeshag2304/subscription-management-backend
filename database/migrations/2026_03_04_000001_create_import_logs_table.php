<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('module');                          // e.g. subscriptions, ssl, hostings
            $table->string('file_name');                       // original uploaded file name
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicate')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('duplicate_file')->nullable();      // relative storage path of dup xlsx
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
