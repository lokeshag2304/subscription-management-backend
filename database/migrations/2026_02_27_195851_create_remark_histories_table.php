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
        Schema::create('remark_histories', function (Blueprint $table) {
            $table->id();
            $table->string('module'); // 'subscription', 'ssl', etc.
            $table->unsignedBigInteger('record_id');
            $table->text('remark');
            $table->string('user_name')->nullable();
            $table->timestamps();

            $table->index(['module', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remark_histories');
    }
};
