<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teen_patti_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teen_patti_round_id')->constrained('teen_patti_rounds')->cascadeOnDelete();
            $table->foreignId('teen_patti_bet_id')->constrained('teen_patti_bets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->unsignedBigInteger('payout_coins');
            $table->string('status', 24)->default('credited')->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['teen_patti_bet_id'], 'teen_patti_payouts_bet_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teen_patti_payouts');
    }
};
