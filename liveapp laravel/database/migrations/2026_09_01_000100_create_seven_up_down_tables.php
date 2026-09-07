<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seven_up_down_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('round_key')->unique('sud_rounds_key_unique');
            $table->string('status', 24)->default('open')->index('sud_rounds_status_idx');
            $table->timestamp('starts_at')->index('sud_rounds_starts_idx');
            $table->timestamp('locks_at')->index('sud_rounds_locks_idx');
            $table->timestamp('ends_at')->index('sud_rounds_ends_idx');
            $table->timestamp('settled_at')->nullable()->index('sud_rounds_settled_idx');
            $table->timestamp('cancelled_at')->nullable()->index('sud_rounds_cancelled_idx');
            $table->string('winning_pot', 16)->nullable()->index('sud_rounds_winner_idx');
            $table->unsignedTinyInteger('winning_multiplier')->nullable();
            $table->string('winning_strategy', 32)->nullable();
            $table->unsignedTinyInteger('dice_one')->nullable();
            $table->unsignedTinyInteger('dice_two')->nullable();
            $table->unsignedTinyInteger('dice_total')->nullable()->index('sud_rounds_dice_total_idx');
            $table->unsignedBigInteger('total_bet_down')->default(0);
            $table->unsignedBigInteger('total_bet_seven')->default(0);
            $table->unsignedBigInteger('total_bet_up')->default(0);
            $table->unsignedInteger('total_bets_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('seven_up_down_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seven_up_down_round_id')->constrained('seven_up_down_rounds', 'id', 'sud_bets_round_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'sud_bets_user_fk')->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions', 'id', 'sud_bets_wallet_tx_fk')->nullOnDelete();
            $table->string('pot', 16)->index('sud_bets_pot_idx');
            $table->unsignedBigInteger('amount');
            $table->unsignedTinyInteger('multiplier');
            $table->unsignedBigInteger('payout_coins')->default(0);
            $table->string('status', 24)->default('placed')->index('sud_bets_status_idx');
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamp('placed_at')->nullable()->index('sud_bets_placed_idx');
            $table->timestamp('settled_at')->nullable()->index('sud_bets_settled_idx');
            $table->timestamp('refunded_at')->nullable()->index('sud_bets_refunded_idx');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['seven_up_down_round_id', 'user_id'], 'sud_bets_round_user_idx');
            $table->unique(['seven_up_down_round_id', 'user_id', 'idempotency_key'], 'sud_bets_round_user_idem_unique');
        });

        Schema::create('seven_up_down_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seven_up_down_round_id')->constrained('seven_up_down_rounds', 'id', 'sud_payouts_round_fk')->cascadeOnDelete();
            $table->foreignId('seven_up_down_bet_id')->constrained('seven_up_down_bets', 'id', 'sud_payouts_bet_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'sud_payouts_user_fk')->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions', 'id', 'sud_payouts_wallet_tx_fk')->nullOnDelete();
            $table->unsignedBigInteger('payout_coins');
            $table->string('status', 24)->default('credited')->index('sud_payouts_status_idx');
            $table->timestamp('settled_at')->nullable()->index('sud_payouts_settled_idx');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique('seven_up_down_bet_id', 'sud_payouts_bet_unique');
        });

        Schema::create('seven_up_down_financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64)->unique('sud_fin_accounts_game_unique');
            $table->bigInteger('treasury_balance_coins')->default(0);
            $table->bigInteger('company_commission_balance_coins')->default(0);
            $table->timestamps();
        });

        Schema::create('seven_up_down_financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seven_up_down_financial_account_id')->constrained('seven_up_down_financial_accounts', 'id', 'sud_fin_ledger_account_fk')->cascadeOnDelete();
            $table->foreignId('seven_up_down_round_id')->nullable()->constrained('seven_up_down_rounds', 'id', 'sud_fin_ledger_round_fk')->nullOnDelete();
            $table->foreignId('seven_up_down_bet_id')->nullable()->constrained('seven_up_down_bets', 'id', 'sud_fin_ledger_bet_fk')->nullOnDelete();
            $table->foreignId('seven_up_down_payout_id')->nullable()->constrained('seven_up_down_payouts', 'id', 'sud_fin_ledger_payout_fk')->nullOnDelete();
            $table->string('event_key', 160)->unique('sud_fin_ledger_event_unique');
            $table->string('event_type', 48)->index('sud_fin_ledger_type_idx');
            $table->bigInteger('treasury_delta_coins')->default(0);
            $table->bigInteger('commission_delta_coins')->default(0);
            $table->bigInteger('treasury_balance_after_coins');
            $table->bigInteger('commission_balance_after_coins');
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index('sud_fin_ledger_occurred_idx');
            $table->timestamps();
            $table->index(['seven_up_down_round_id', 'event_type'], 'sud_fin_round_event_idx');
        });

        DB::table('seven_up_down_financial_accounts')->insert([
            'game_key' => 'seven_up_down',
            'treasury_balance_coins' => 0,
            'company_commission_balance_coins' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('seven_up_down_financial_ledger_entries');
        Schema::dropIfExists('seven_up_down_financial_accounts');
        Schema::dropIfExists('seven_up_down_payouts');
        Schema::dropIfExists('seven_up_down_bets');
        Schema::dropIfExists('seven_up_down_rounds');
    }
};
