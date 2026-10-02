<?php

namespace Tests\Feature;

use App\Models\PaymentOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\RechargeOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RechargeAnomalyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recharge_anomalies_keep_their_counts_without_per_order_queries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $wallet = Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        $otherWallet = Wallet::query()->updateOrCreate(['user_id' => $otherUser->id], ['balance' => 0]);

        $missing = $this->order($user, 'missing');
        $matching = $this->order($user, 'matching');
        $mismatch = $this->order($user, 'mismatch');
        for ($index = 0; $index < 25; $index++) {
            $this->order($user, 'extra-'.$index);
        }

        $this->rechargeCredit($wallet, $matching->id, 1000);
        $this->rechargeCredit($wallet, $mismatch->id, 900);
        $this->rechargeCredit($otherWallet, $mismatch->id, 1000);
        $this->rechargeCredit($wallet, 999999, 1000);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $anomalies = app(RechargeOrderService::class)->anomalies();
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(26, $anomalies['payment_success_without_wallet_transaction']);
        $this->assertSame(1, $anomalies['wallet_transaction_without_payment_order']);
        $this->assertSame(1, $anomalies['duplicate_recharge_credits']);
        $this->assertSame(1, $anomalies['mismatched_recharge_coin_amount']);
        $this->assertLessThanOrEqual(15, $queryCount);
        $this->assertNotNull($missing->id);
    }

    private function order(User $user, string $suffix): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $user->id,
            'order_id' => 'recharge-performance-'.$suffix,
            'amount_rupees' => 100,
            'coins' => 1000,
            'total_coins' => 1000,
            'status' => 'success',
        ]);
    }

    private function rechargeCredit(Wallet $wallet, int $orderId, int $coins): void
    {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'coins' => $coins,
            'category' => 'recharge',
            'reference' => 'payment_order:'.$orderId,
            'reference_type' => 'payment_order',
            'reference_id' => $orderId,
        ]);
    }
}
