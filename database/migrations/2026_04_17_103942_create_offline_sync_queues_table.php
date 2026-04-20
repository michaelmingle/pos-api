<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('user_id');
            $table->string('device_id');
            $table->string('table_name');
            $table->string('operation'); // create, update, delete
            $table->json('data');
            $table->json('old_data')->nullable();
            $table->enum('status', ['pending', 'synced', 'failed'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['shop_id', 'status']);
            $table->index(['device_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('offline_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('cache_key');
            $table->json('data');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->unique(['shop_id', 'cache_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_cache');
        Schema::dropIfExists('offline_sync_queue');
    }
};