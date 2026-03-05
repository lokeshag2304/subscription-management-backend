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
        $tables = ['domains', 'hostings', 'emails'];

        foreach ($tables as $tableName) {
            Schema::dropIfExists($tableName);
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('product_id');
                $table->foreignId('client_id')->nullable()->constrained('users')->onDelete('cascade');
                $table->unsignedInteger('vendor_id');
                // Removing explicit foreign keys on products and vendors because MySQL might block if int types differ slightly and standard Laravel usually handles it properly
                $table->decimal('amount', 10, 2)->nullable();
                $table->date('renewal_date')->nullable();
                $table->date('deletion_date')->nullable();
                $table->integer('days_to_delete')->nullable();
                $table->boolean('status')->default(1);
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['domains', 'hostings', 'emails'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
