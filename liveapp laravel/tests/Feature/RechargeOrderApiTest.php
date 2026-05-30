<?php

namespace Tests\Feature;

use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use App\Services\RazorpayGatewayService;
use Database\Seeders\RechargePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RechargeOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        config(['services.mock_payments.enabled' => true]);
        $this->seed(RechargePlanSeeder::class);
    }

    public function test_recharge_order_can_be_created(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();

        $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.recharge_plan_id', $plan->id);

        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'recharge_plan_id' => $plan->id,
            'status' => 'pending',
        ]);
    }

    public function test_verify_success_credits_wallet_once(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 100]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $response = $this->postJson("/api/recharge/orders/{$orderId}/verify", [
            'result' => 'success',
            'gateway_payment_id' => 'mock_txn_1',
            'gateway_response' => ['approved' => true],
        ])->assertOk();

        $this->assertSame(600, $response->json('data.wallet_balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge',
            'reference_type' => 'payment_order',
            'transaction_id' => 'mock_txn_1',
            'coins' => 500,
            'balance_before' => 100,
            'balance_after' => 600,
        ]);
    }

    public function test_verify_is_idempotent_and_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 0]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();
        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(500, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_failed_verify_does_not_credit_wallet(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 50]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'failed'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('data.order.status', 'failed');

        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'recharge',
        ]);
        $this->assertSame(50, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_failed_verify_is_idempotent_for_closed_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 50]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'failed'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonPath('data.already_processed', false);

        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'failed'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'failed')
            ->assertJsonPath('data.already_processed', true);

        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'recharge',
        ]);
        $this->assertSame(50, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_wallet_transactions_endpoint_includes_recharge_entries_and_filters(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', ['plan_id' => $plan->id])->json('data.order_id');
        $this->postJson("/api/recharge/orders/{$orderId}/verify", ['result' => 'success'])->assertOk();

        $this->getJson('/api/wallet/transactions?filter=recharge')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.category', 'recharge');
    }

    public function test_razorpay_order_can_be_created_with_checkout_payload(): void
    {
        config(['services.mock_payments.enabled' => false]);
        $gateway = $this->bindFakeRazorpayGateway();

        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();

        $this->postJson('/api/recharge/orders', [
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
        ])
            ->assertCreated()
            ->assertJsonPath('data.gateway', 'razorpay')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.checkout.gateway', 'razorpay')
            ->assertJsonPath('data.checkout.key', 'rzp_test_key')
            ->assertJsonPath('data.checkout.order_id', 'order_test_123')
            ->assertJsonPath('data.checkout.method.upi', true);

        $this->assertNotEmpty($gateway->createdOrders);
    }

    public function test_razorpay_verify_success_credits_wallet_once(): void
    {
        config(['services.mock_payments.enabled' => false]);
        $gateway = $this->bindFakeRazorpayGateway([
            'payments' => [
                'pay_test_123' => [
                    'id' => 'pay_test_123',
                    'order_id' => 'order_test_123',
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'captured',
                ],
            ],
        ]);

        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 100]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', [
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
        ])->json('data.order_id');

        $signature = $gateway->paymentSignature('order_test_123', 'pay_test_123');

        $response = $this->postJson("/api/recharge/orders/{$orderId}/verify", [
            'result' => 'success',
            'gateway_payment_id' => 'pay_test_123',
            'gateway_order_id' => 'order_test_123',
            'gateway_signature' => $signature,
        ])->assertOk();

        $this->assertSame(600, $response->json('data.wallet_balance'));
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge',
            'reference_type' => 'payment_order',
            'transaction_id' => 'pay_test_123',
            'coins' => 500,
            'balance_before' => 100,
            'balance_after' => 600,
        ]);
    }

    public function test_razorpay_webhook_can_credit_order_without_client_verify(): void
    {
        config(['services.mock_payments.enabled' => false]);
        $gateway = $this->bindFakeRazorpayGateway();

        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 50]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $order = $this->postJson('/api/recharge/orders', [
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
        ])->json('data');

        $payload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_webhook_1',
                        'order_id' => 'order_test_123',
                        'amount' => 10000,
                        'currency' => 'INR',
                        'status' => 'captured',
                    ],
                ],
                'order' => [
                    'entity' => [
                        'id' => 'order_test_123',
                    ],
                ],
            ],
        ];

        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $gateway->webhookSignature($rawPayload);

        $this->postJson(
            '/api/payments/razorpay/webhook',
            $payload,
            ['X-Razorpay-Signature' => $signature],
        )->assertOk();

        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge',
            'reference_type' => 'payment_order',
            'transaction_id' => 'pay_webhook_1',
        ]);
        $this->assertDatabaseHas('payment_orders', [
            'order_id' => $order['order_id'],
            'status' => 'success',
            'gateway_payment_id' => 'pay_webhook_1',
        ]);
        $this->assertSame(550, Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_razorpay_verify_returns_pending_when_capture_is_not_complete(): void
    {
        config(['services.mock_payments.enabled' => false]);
        $gateway = $this->bindFakeRazorpayGateway([
            'payments' => [
                'pay_auth_1' => [
                    'id' => 'pay_auth_1',
                    'order_id' => 'order_test_123',
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'authorized',
                ],
            ],
            'capture_exception' => 'Gateway capture is temporarily unavailable.',
        ]);

        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(['user_id' => $user->id], ['balance' => 10]);
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->firstOrFail();
        $orderId = $this->postJson('/api/recharge/orders', [
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
        ])->json('data.order_id');

        $signature = $gateway->paymentSignature('order_test_123', 'pay_auth_1');

        $this->postJson("/api/recharge/orders/{$orderId}/verify", [
            'result' => 'success',
            'gateway_payment_id' => 'pay_auth_1',
            'gateway_order_id' => 'order_test_123',
            'gateway_signature' => $signature,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.order.status', 'authorized');

        $this->assertSame(10, Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'recharge',
            'transaction_id' => 'pay_auth_1',
        ]);
    }

    private function bindFakeRazorpayGateway(array $state = []): object
    {
        $gateway = new class($state) extends RazorpayGatewayService {
            public array $createdOrders = [];

            public function __construct(private array $state = [])
            {
            }

            public function configured(): bool
            {
                return true;
            }

            public function keyId(): string
            {
                return 'rzp_test_key';
            }

            public function currency(): string
            {
                return 'INR';
            }

            public function webhookSecret(): string
            {
                return 'whsec_test';
            }

            public function createOrder(array $payload): array
            {
                $this->createdOrders[] = $payload;

                return $this->state['create_order'] ?? [
                    'id' => 'order_test_123',
                    'status' => 'created',
                ];
            }

            public function fetchPayment(string $paymentId): array
            {
                return $this->state['payments'][$paymentId] ?? [
                    'id' => $paymentId,
                    'order_id' => 'order_test_123',
                    'amount' => 10000,
                    'currency' => 'INR',
                    'status' => 'captured',
                ];
            }

            public function capturePayment(string $paymentId, int $amountSubunits, string $currency): array
            {
                if (!empty($this->state['capture_exception'])) {
                    throw new \InvalidArgumentException($this->state['capture_exception']);
                }

                return $this->state['capture_payment'] ?? [
                    'id' => $paymentId,
                    'order_id' => 'order_test_123',
                    'amount' => $amountSubunits,
                    'currency' => $currency,
                    'status' => 'captured',
                ];
            }

            public function verifySignature(
                string $gatewayOrderId,
                string $paymentId,
                string $signature,
            ): bool {
                return hash_equals(
                    hash_hmac('sha256', $gatewayOrderId . '|' . $paymentId, 'key_secret_test'),
                    trim($signature),
                );
            }

            public function verifyWebhookSignature(string $payload, string $signature): bool
            {
                return hash_equals(
                    hash_hmac('sha256', $payload, 'whsec_test'),
                    trim($signature),
                );
            }

            public function paymentSignature(string $gatewayOrderId, string $paymentId): string
            {
                return hash_hmac('sha256', $gatewayOrderId . '|' . $paymentId, 'key_secret_test');
            }

            public function webhookSignature(string $payload): string
            {
                return hash_hmac('sha256', $payload, 'whsec_test');
            }
        };

        $this->app->instance(RazorpayGatewayService::class, $gateway);

        return $gateway;
    }
}
