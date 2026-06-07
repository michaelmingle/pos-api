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
        // SMS table
        Schema::create('s_m_s', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable();
            $table->string('recipient');
            $table->text('message');
            $table->string('type')->default('single'); // single, bulk
            $table->string('status')->default('sent');
            $table->string('message_id')->nullable();
            $table->decimal('cost', 10, 4)->nullable();
            $table->decimal('balance_before', 10, 4)->nullable();
            $table->decimal('balance_after', 10, 4)->nullable();
            $table->json('cost_info')->nullable();
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('recipient');
            $table->index('created_at');
        });
        
        // SMS Transactions table
        Schema::create('sms_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('message_id')->nullable();
            $table->string('recipient')->nullable();
            $table->decimal('cost', 10, 4);
            $table->integer('recipient_count')->default(1);
            $table->decimal('balance_before', 10, 4);
            $table->decimal('balance_after', 10, 4);
            $table->json('cost_info')->nullable();
            $table->string('type')->default('single'); // single, bulk
            $table->timestamps();
            
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->index('shop_id');
            $table->index('created_at');
        });
        
        // SMS Balance table
        Schema::create('sms_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id')->unique();
            $table->decimal('balance', 10, 4)->default(0);
            $table->integer('total_sent')->default(0);
            $table->decimal('total_cost', 10, 4)->default(0);
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
            
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_balances');
        Schema::dropIfExists('sms_transactions');
        Schema::dropIfExists('s_m_s');
    }
};
