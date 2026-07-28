<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_app_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('event_name', 64)->index();
            $table->string('source', 20)->default('app');
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->boolean('advertiser_tracking_enabled')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('properties')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['event_name', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->unique('payment_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_app_events');
    }
};
