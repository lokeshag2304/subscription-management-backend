<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            // Add all columns the ActivitiesController & UserManagement expect
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->text('action')->nullable()->after('user_id');
            $table->text('s_action')->nullable()->after('action');
            $table->text('message')->nullable()->after('s_action');
            $table->text('s_message')->nullable()->after('message');
            $table->text('details')->nullable()->after('s_message');
            $table->string('module')->nullable()->after('details'); // e.g. Subscription, SSL, Hosting
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'action', 's_action', 'message', 's_message', 'details', 'module']);
        });
    }
};
