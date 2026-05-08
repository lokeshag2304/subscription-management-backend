<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('superadmins', function (Blueprint $table) {
            if (!Schema::hasColumn('superadmins', 'country_code')) {
                $table->string('country_code', 10)->nullable()->after('country');
            }
            if (!Schema::hasColumn('superadmins', 'dial_code')) {
                $table->string('dial_code', 10)->nullable()->after('country_code');
            }
            if (!Schema::hasColumn('superadmins', 'phone_number')) {
                $table->string('phone_number', 20)->nullable()->after('dial_code');
            }
        });
    }

    public function down()
    {
        Schema::table('superadmins', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'dial_code', 'phone_number']);
        });
    }
};
