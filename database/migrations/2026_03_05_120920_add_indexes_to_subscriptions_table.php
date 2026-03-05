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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index('product_id');
            $table->index('client_id');
            $table->index('vendor_id');
            $table->index('renewal_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['client_id']);
            $table->dropIndex(['vendor_id']);
            $table->dropIndex(['renewal_date']);
        });
    }
};
