<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('subscription_models', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->string('client_name');
            $table->decimal('amount', 10, 2);
            $table->date('renewal_date');
            $table->date('deletion_date')->nullable();
            $table->integer('days_left')->nullable();
            $table->integer('days_to_delete')->nullable();
            $table->boolean('status')->default(1);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_models');
    }
};
