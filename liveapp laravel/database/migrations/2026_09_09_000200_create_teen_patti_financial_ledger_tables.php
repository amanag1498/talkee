<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teen_patti_financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('game_key', 64)->unique();
            $table->bigInteger('treasury_balance_coins')->default(0);
            $table->bigInteger('company_commission_balance_coins')->default(0);
            $table->timestamps();
        });

        Schema::create('teen_patti_financial_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teen_patti_financial_account_id');
            $table->unsignedBigInteger('teen_patti_round_id')->nullable();
            $table->unsignedBigInteger('teen_patti_bet_id')->nullable();
            $table->unsignedBigInteger('teen_patti_payout_id')->nullable();
            $table->string('event_key', 160)->unique();
            $table->string('event_type', 48)->index();
            $table->bigInteger('treasury_delta_coins')->default(0);
            $table->bigInteger('commission_delta_coins')->default(0);
            $table->bigInteger('treasury_balance_after_coins');
            $table->bigInteger('commission_balance_after_coins');
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['teen_patti_round_id', 'event_type'], 'teen_patti_financial_round_event_idx');
            $table->foreign('teen_patti_financial_account_id', 'tp_fin_ledger_account_fk')
                ->references('id')->on('teen_patti_financial_accounts')->cascadeOnDelete();
            $table->foreign('teen_patti_round_id', 'tp_fin_ledger_round_fk')
                ->references('id')->on('teen_patti_rounds')->nullOnDelete();
            $table->foreign('teen_patti_bet_id', 'tp_fin_ledger_bet_fk')
                ->references('id')->on('teen_patti_bets')->nullOnDelete();
            $table->foreign('teen_patti_payout_id', 'tp_fin_ledger_payout_fk')
                ->references('id')->on('teen_patti_payouts')->nullOnDelete();
        });

        DB::table('teen_patti_financial_accounts')->insert([
            'game_key' => 'teen_patti',
            'treasury_balance_coins' => 0,
            'company_commission_balance_coins' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('teen_patti_financial_ledger_entries');
        Schema::dropIfExists('teen_patti_financial_accounts');
    }
};
