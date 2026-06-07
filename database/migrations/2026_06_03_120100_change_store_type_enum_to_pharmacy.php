<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert any legacy restaurant rows to supermarket so the enum change is safe.
        DB::table('shops')->where('store_type', 'restaurant')->update(['store_type' => 'supermarket']);

        Schema::table('shops', function (Blueprint $table) {
            $table->string('store_type', 32)->default('supermarket')->change();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->enum('store_type', ['supermarket', 'restaurant'])->default('supermarket')->change();
        });
    }
};
