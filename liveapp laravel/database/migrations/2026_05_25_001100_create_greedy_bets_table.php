<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greedy_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('greedy_round_id')->constrained('greedy_rounds')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->char('pot', 1)->index();
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('multiplier');
            $table->unsignedBigInteger('payout_coins')->default(0);
            $table->string('status', 24)->default('placed')->index();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->timestamp('refunded_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['greedy_round_id', 'user_id']);
            $table->unique(['greedy_round_id', 'user_id', 'idempotency_key'], 'greedy_bets_round_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greedy_bets');
    }
};
