<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greedy_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('greedy_round_id')->constrained('greedy_rounds')->cascadeOnDelete();
            $table->foreignId('greedy_bet_id')->constrained('greedy_bets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->unsignedBigInteger('payout_coins');
            $table->string('status', 24)->default('credited')->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['greedy_bet_id'], 'greedy_payouts_bet_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greedy_payouts');
    }
};
