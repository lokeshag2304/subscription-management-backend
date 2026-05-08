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
        Schema::create('suffix_masters', function (Blueprint $row) {
            $row->id();
            $row->string('suffix')->unique();
            $row->timestamps();
        });

        // Pre-populate with some common suffixes based on current data
        $commonSuffixes = ['com', 'in', 'net', 'org', 'biz', 'co', 'io', 'info', 'me', 'tv'];
        foreach ($commonSuffixes as $suffix) {
            \App\Models\SuffixMaster::firstOrCreate(['suffix' => $suffix]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suffix_masters');
    }
};
