<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('stock_quantity');
                $table->index('expiry_date');
            }
            if (!Schema::hasColumn('products', 'damaged_quantity')) {
                $table->integer('damaged_quantity')->default(0)->after('expiry_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'damaged_quantity')) {
                $table->dropColumn('damaged_quantity');
            }
            if (Schema::hasColumn('products', 'expiry_date')) {
                $table->dropIndex(['expiry_date']);
                $table->dropColumn('expiry_date');
            }
        });
    }
};
