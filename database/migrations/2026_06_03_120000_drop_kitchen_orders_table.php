<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('kitchen_orders');
    }

    public function down(): void
    {
        // Restaurant/kitchen feature has been removed; no rollback.
    }
};
