<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortune_wheel_segments', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('reward_type', 24)->index();
            $table->unsignedBigInteger('reward_value_coins')->default(0);
            $table->foreignId('entry_pack_id')->nullable()->constrained('entry_packs')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->unsignedInteger('reward_duration_hours')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->string('color', 32)->nullable();
            $table->string('icon_url', 2048)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('fortune_wheel_spins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fortune_wheel_segment_id')->nullable()->constrained('fortune_wheel_segments')->nullOnDelete();
            $table->string('spin_type', 16)->index();
            $table->unsignedBigInteger('spin_cost_coins')->default(0);
            $table->string('reward_type', 24)->index();
            $table->unsignedBigInteger('reward_value_coins')->default(0);
            $table->foreignId('entry_pack_id')->nullable()->constrained('entry_packs')->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->unsignedInteger('reward_duration_hours')->nullable();
            $table->foreignId('wallet_debit_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('wallet_credit_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('user_entry_pack_id')->nullable()->constrained('user_entry_packs')->nullOnDelete();
            $table->foreignId('user_subscription_id')->nullable()->constrained('user_subscriptions')->nullOnDelete();
            $table->string('idempotency_key', 160)->nullable();
            $table->date('spun_for_date')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'spun_for_date', 'spin_type'], 'fortune_wheel_user_date_type_idx');
            $table->unique(['user_id', 'idempotency_key'], 'fortune_wheel_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_wheel_spins');
        Schema::dropIfExists('fortune_wheel_segments');
    }
};
