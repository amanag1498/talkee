<?php

namespace Tests\Feature;

use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AppleAppStoreService;
use Database\Seeders\RechargePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppleInAppPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agency', 'host', 'user'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(RechargePlanSeeder::class);
    }

    public function test_ios_catalog_exposes_only_apple_configured_coin_packs(): void
    {
        RechargePlan::query()->firstOrFail()->update(['apple_product_id' => null]);

        $this->withHeader('X-Client-Platform', 'ios')
            ->getJson('/api/recharge/plans')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['apple_product_id' => null]);
    }

    public function test_verified_apple_consumable_credits_wallet_exactly_once(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Wallet::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['balance' => 100],
        );
        Sanctum::actingAs($user);

        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();
        $transactionId = '2000000999000012';
        $this->fakeAppleTransaction($user, $plan, $transactionId);

        $payload = [
            'product_id' => $plan->apple_product_id,
            'transaction_id' => $transactionId,
        ];

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', $payload)
            ->assertOk()
            ->assertJsonPath('data.wallet_balance', 600)
            ->assertJsonPath('data.already_processed', false);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', $payload)
            ->assertOk()
            ->assertJsonPath('data.wallet_balance', 600)
            ->assertJsonPath('data.already_processed', true);

        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $user->id,
            'gateway' => 'apple_iap',
            'apple_transaction_id' => $transactionId,
            'store_product_id' => $plan->apple_product_id,
            'store_currency' => 'INR',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'gateway' => 'apple_iap',
            'transaction_id' => $transactionId,
            'coins' => 500,
            'balance_before' => 100,
            'balance_after' => 600,
        ]);
    }

    public function test_apple_purchase_rejects_a_product_mismatch(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();

        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('transaction')->once()->andReturn([
            'transactionId' => '2000000999000013',
            'bundleId' => 'com.techybugs.talkee',
            'productId' => 'com.techybugs.talkee.coins.13000',
            'type' => 'CONSUMABLE',
            'environment' => 'Sandbox',
            'appAccountToken' => $this->accountToken($user),
        ]);
        $this->app->instance(AppleAppStoreService::class, $apple);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => '2000000999000013',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Apple purchase product does not match this coin pack.',
            );

        $this->assertDatabaseCount('payment_orders', 0);
    }

    public function test_apple_purchase_endpoint_rejects_non_ios_clients(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->firstOrFail();

        $this->withHeader('X-Client-Platform', 'android')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => '2000000999000014',
            ])
            ->assertUnprocessable();
    }

    public function test_apple_refund_notification_is_idempotent_and_records_unrecovered_coins(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();
        $transactionId = '2000000999000015';
        $this->fakeAppleTransaction($user, $plan, $transactionId);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => $transactionId,
            ])
            ->assertOk();

        Wallet::query()->where('user_id', $user->id)->update(['balance' => 200]);
        $notification = [
            'notification_uuid' => 'notification-refund-1',
            'notification_type' => 'REFUND',
            'subtype' => null,
            'transaction' => [
                'transactionId' => $transactionId,
                'bundleId' => 'com.techybugs.talkee',
                'productId' => $plan->apple_product_id,
                'type' => 'CONSUMABLE',
                'environment' => 'Sandbox',
                'appAccountToken' => $this->accountToken($user),
                'revocationDate' => now()->getTimestampMs(),
            ],
        ];
        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('notification')
            ->twice()
            ->with('signed-notification')
            ->andReturn($notification);
        $this->app->instance(AppleAppStoreService::class, $apple);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.processed', true)
            ->assertJsonPath('data.recovered_coins', 200)
            ->assertJsonPath('data.unrecovered_coins', 300);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'already_processed');

        $this->assertSame(
            0,
            (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
        );
        $this->assertDatabaseHas('payment_orders', [
            'apple_transaction_id' => $transactionId,
            'status' => 'refunded',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge_refund',
            'reference_type' => 'payment_order_refund',
            'transaction_id' => $transactionId,
            'coins' => 200,
            'balance_before' => 200,
            'balance_after' => 0,
        ]);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(0, (int) $user->fresh()->lifetime_spend_coins);
    }

    public function test_apple_partial_refund_reversal_restores_only_recovered_coins(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();
        $transactionId = '2000000999000016';
        $this->fakeAppleTransaction($user, $plan, $transactionId);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => $transactionId,
            ])
            ->assertOk();

        $refund = [
            'notification_uuid' => 'notification-partial-refund',
            'notification_type' => 'REFUND',
            'subtype' => null,
            'transaction' => [
                'transactionId' => $transactionId,
                'bundleId' => 'com.techybugs.talkee',
                'productId' => $plan->apple_product_id,
                'type' => 'CONSUMABLE',
                'environment' => 'Sandbox',
                'appAccountToken' => $this->accountToken($user),
                'revocationDate' => now()->getTimestampMs(),
                'revocationPercentage' => 50000,
            ],
        ];
        $reversal = [
            'notification_uuid' => 'notification-refund-reversed',
            'notification_type' => 'REFUND_REVERSED',
            'subtype' => null,
            'transaction' => [
                'transactionId' => $transactionId,
                'bundleId' => 'com.techybugs.talkee',
                'productId' => $plan->apple_product_id,
                'type' => 'CONSUMABLE',
                'environment' => 'Sandbox',
                'appAccountToken' => $this->accountToken($user),
            ],
        ];
        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('notification')
            ->twice()
            ->with('signed-notification')
            ->andReturn($refund, $reversal);
        $this->app->instance(AppleAppStoreService::class, $apple);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.refunded_coins', 250)
            ->assertJsonPath('data.recovered_coins', 250);

        $this->assertDatabaseHas('payment_orders', [
            'apple_transaction_id' => $transactionId,
            'status' => 'partially_refunded',
        ]);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'refund_reversed')
            ->assertJsonPath('data.restored_coins', 250);

        $this->assertSame(
            500,
            (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
        );
        $this->assertDatabaseHas('payment_orders', [
            'apple_transaction_id' => $transactionId,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge_refund_reversal',
            'reference_type' => 'payment_order_refund_reversal',
            'coins' => 250,
        ]);
    }

    public function test_zero_balance_refund_is_recorded_and_cannot_debit_later_coins(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();
        $transactionId = '2000000999000017';
        $this->fakeAppleTransaction($user, $plan, $transactionId);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => $transactionId,
            ])
            ->assertOk();

        Wallet::query()->where('user_id', $user->id)->update(['balance' => 0]);
        $notification = [
            'notification_uuid' => 'notification-zero-refund-1',
            'notification_type' => 'REFUND',
            'subtype' => null,
            'transaction' => [
                'transactionId' => $transactionId,
                'bundleId' => 'com.techybugs.talkee',
                'productId' => $plan->apple_product_id,
                'type' => 'CONSUMABLE',
                'environment' => 'Sandbox',
                'appAccountToken' => $this->accountToken($user),
                'revocationDate' => now()->getTimestampMs(),
            ],
        ];
        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('notification')
            ->twice()
            ->with('signed-notification')
            ->andReturnUsing(function () use (&$notification) {
                $result = $notification;
                $notification['notification_uuid'] = 'notification-zero-refund-2';

                return $result;
            });
        $this->app->instance(AppleAppStoreService::class, $apple);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.recovered_coins', 0)
            ->assertJsonPath('data.unrecovered_coins', 500);

        Wallet::query()->where('user_id', $user->id)->update(['balance' => 100]);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.recovered_coins', 0);

        $this->assertSame(
            100,
            (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
        );
        $this->assertDatabaseHas('wallet_transactions', [
            'category' => 'recharge_refund',
            'reference_type' => 'payment_order_refund',
            'coins' => 0,
        ]);
    }

    public function test_notification_label_cannot_reverse_an_authoritative_apple_refund(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        Sanctum::actingAs($user);
        $plan = RechargePlan::query()->orderBy('sort_order')->firstOrFail();
        $transactionId = '2000000999000018';
        $this->fakeAppleTransaction($user, $plan, $transactionId);

        $this->withHeader('X-Client-Platform', 'ios')
            ->postJson('/api/recharge/apple/verify', [
                'product_id' => $plan->apple_product_id,
                'transaction_id' => $transactionId,
            ])
            ->assertOk();

        $revokedTransaction = [
            'transactionId' => $transactionId,
            'bundleId' => 'com.techybugs.talkee',
            'productId' => $plan->apple_product_id,
            'type' => 'CONSUMABLE',
            'environment' => 'Sandbox',
            'appAccountToken' => $this->accountToken($user),
            'revocationDate' => now()->getTimestampMs(),
        ];
        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('notification')
            ->twice()
            ->andReturn(
                [
                    'notification_uuid' => 'authoritative-refund',
                    'notification_type' => 'REFUND',
                    'transaction' => $revokedTransaction,
                ],
                [
                    'notification_uuid' => 'misleading-reversal-label',
                    'notification_type' => 'REFUND_REVERSED',
                    'transaction' => $revokedTransaction,
                ],
            );
        $this->app->instance(AppleAppStoreService::class, $apple);

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])->assertOk();
        $balanceAfterRefund = (int) $user->wallet()->value('balance');

        $this->postJson('/api/payments/apple/notifications', [
            'signedPayload' => 'signed-notification',
        ])
            ->assertOk()
            ->assertJsonPath('data.reason', 'refunded');

        $this->assertSame(
            $balanceAfterRefund,
            (int) $user->wallet()->value('balance'),
        );
        $this->assertDatabaseHas('payment_orders', [
            'apple_transaction_id' => $transactionId,
            'status' => 'refunded',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'category' => 'recharge_refund_reversal',
            'transaction_id' => $transactionId,
        ]);
    }

    private function fakeAppleTransaction(
        User $user,
        RechargePlan $plan,
        string $transactionId,
    ): void {
        $apple = Mockery::mock(AppleAppStoreService::class);
        $apple->shouldReceive('transaction')->atLeast()->once()->with($transactionId)->andReturn([
            'transactionId' => $transactionId,
            'originalTransactionId' => $transactionId,
            'bundleId' => 'com.techybugs.talkee',
            'productId' => $plan->apple_product_id,
            'type' => 'CONSUMABLE',
            'environment' => 'Sandbox',
            'appAccountToken' => $this->accountToken($user),
            'price' => 49000,
            'currency' => 'INR',
            'purchaseDate' => now()->getTimestampMs(),
        ]);
        $this->app->instance(AppleAppStoreService::class, $apple);
    }

    private function accountToken(User $user): string
    {
        $namespace = hex2bin('6ba7b8119dad11d180b400c04fd430c8');
        $hash = sha1($namespace.'com.techybugs.talkee:user:'.$user->id, true);
        $bytes = array_values(unpack('C16', substr($hash, 0, 16)));
        $bytes[6] = ($bytes[6] & 0x0F) | 0x50;
        $bytes[8] = ($bytes[8] & 0x3F) | 0x80;
        $hex = implode('', array_map(fn (int $byte) => sprintf('%02x', $byte), $bytes));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
