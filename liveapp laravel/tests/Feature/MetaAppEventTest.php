<?php

namespace Tests\Feature;

use App\Models\MetaAppEvent;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\MetaAppEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MetaAppEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin', 'web');
    }

    public function test_authenticated_app_event_is_validated_and_deduplicated(): void
    {
        $user = User::factory()->create();
        $eventId = '8dc989e8-5755-44c2-a1f4-d9cb49053287';
        $payload = [
            'event_id' => $eventId,
            'event_name' => 'login',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'properties' => [
                'login_provider' => 'google',
                'email' => 'must-not-be-stored@example.com',
            ],
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/marketing/meta-events', $payload)
            ->assertCreated()
            ->assertJsonPath('data.event_id', $eventId);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/marketing/meta-events', $payload)
            ->assertCreated();

        $this->assertDatabaseCount('meta_app_events', 1);
        $event = MetaAppEvent::query()->firstOrFail();
        $this->assertSame(['login_provider' => 'google'], $event->properties);
    }

    public function test_verified_purchase_audit_uses_server_order_value_once(): void
    {
        $user = User::factory()->create();
        $order = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'order_id' => 'meta-audit-order-1',
            'amount_rupees' => 499.00,
            'coins' => 4500,
            'bonus_coins' => 500,
            'total_coins' => 5000,
            'status' => 'success',
            'gateway' => 'razorpay',
            'verified_at' => now(),
        ]);
        $request = Request::create('/api/recharge/orders/meta-audit-order-1/verify', 'POST');

        $recorder = app(MetaAppEventRecorder::class);
        $recorder->recordVerifiedPurchase($order, $request);
        $recorder->recordVerifiedPurchase($order, $request);

        $this->assertDatabaseCount('meta_app_events', 1);
        $this->assertDatabaseHas('meta_app_events', [
            'payment_order_id' => $order->id,
            'event_name' => 'purchase',
            'source' => 'server',
            'value' => 499.00,
            'currency' => 'INR',
        ]);
    }

    public function test_app_cannot_forge_server_authoritative_registration_or_purchase_events(): void
    {
        $user = User::factory()->create();

        foreach (['complete_registration', 'purchase'] as $eventName) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/marketing/meta-events', [
                    'event_id' => (string) \Illuminate\Support\Str::uuid(),
                    'event_name' => $eventName,
                    'platform' => 'android',
                ])
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('meta_app_events', 0);
    }

    public function test_admin_can_open_all_meta_visibility_tabs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        foreach ([
            'overview' => 'Conversion Funnel',
            'events' => 'Event Audit',
            'setup' => 'Integration Health',
        ] as $tab => $expected) {
            $this->actingAs($admin)
                ->get(route('admin.meta-app-events.index', ['tab' => $tab]))
                ->assertOk()
                ->assertSee($expected);
        }
    }

    public function test_missing_meta_migration_does_not_break_admin_or_app_requests(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create();

        Schema::dropIfExists('meta_app_events');

        $this->actingAs($admin)
            ->get(route('admin.meta-app-events.index'))
            ->assertOk()
            ->assertSee('Meta event database migration is missing');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/marketing/meta-events', [
                'event_id' => 'ddcf43ac-9fd5-48fa-b68d-4d6d8a2bc771',
                'event_name' => 'login',
                'platform' => 'android',
            ])
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }
}
