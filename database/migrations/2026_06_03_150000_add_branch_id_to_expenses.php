<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('expenses') || Schema::hasColumn('expenses', 'branch_id')) {
            return;
        }
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->after('shop_id');
            $table->index(['shop_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('expenses') || !Schema::hasColumn('expenses', 'branch_id')) {
            return;
        }
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
