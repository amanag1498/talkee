<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teen_patti_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teen_patti_round_id')->constrained('teen_patti_rounds')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->char('pot', 1)->index();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('payout_coins')->default(0);
            $table->string('status', 24)->default('placed')->index();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->timestamp('refunded_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['teen_patti_round_id', 'user_id']);
            $table->unique(['teen_patti_round_id', 'user_id', 'idempotency_key'], 'teen_patti_bets_round_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teen_patti_bets');
    }
};
