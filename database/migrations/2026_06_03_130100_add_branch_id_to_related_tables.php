<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['users', 'products', 'customers', 'invoices'];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'branch_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->uuid('branch_id')->nullable()->after('shop_id');
                $blueprint->index(['shop_id', 'branch_id']);
            });
        }
    }

    public function down(): void
    {
        $tables = ['users', 'products', 'customers', 'invoices'];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'branch_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex(['shop_id', 'branch_id']);
                $blueprint->dropColumn('branch_id');
            });
        }
    }
};
