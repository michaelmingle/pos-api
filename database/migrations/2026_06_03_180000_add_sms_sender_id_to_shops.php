<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shops') || Schema::hasColumn('shops', 'sms_sender_id')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            // Paystack/CSMS sender IDs are alphanumeric, max 11 chars.
            $table->string('sms_sender_id', 11)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops') || !Schema::hasColumn('shops', 'sms_sender_id')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('sms_sender_id');
        });
    }
};
