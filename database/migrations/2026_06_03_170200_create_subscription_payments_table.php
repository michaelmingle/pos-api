<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subscription_id');
            $table->string('paystack_reference')->unique();
            $table->unsignedInteger('amount_pesewas');
            $table->string('currency', 8)->default('GHS');
            $table->enum('status', ['pending', 'success', 'failed', 'abandoned'])->default('pending');
            $table->enum('channel', ['card', 'mobile_money', 'bank', 'ussd', 'other'])->default('other');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
