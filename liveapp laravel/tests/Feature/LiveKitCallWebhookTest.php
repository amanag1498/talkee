<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\Host;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BillingReconciliationService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveKitCallWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_caller_departure_ends_call_at_media_time_and_refunds_late_billing(): void
    {
        Config::set('services.livekit.api_key', 'test-key');
        Config::set('services.livekit.api_secret', 'test-secret');
        Http::fake(['*' => Http::response([], 200)]);

        $caller = User::factory()->create();
        $receiver = User::factory()->create();
        $host = Host::query()->create(['user_id' => $receiver->id, 'stage_name' => 'Media Host']);
        $wallet = Wallet::query()->updateOrCreate(['user_id' => $caller->id], ['balance' => 2000]);
        $startedAt = now()->subMinutes(3)->startOfSecond();
        $call = CallSession::query()->create([
            'caller_id' => $caller->id,
            'receiver_id' => $receiver->id,
            'host_id' => $host->id,
            'type' => 'video',
            'status' => 'accepted',
            'livekit_room_name' => 'call_test_departure',
            'accepted_at' => $startedAt,
            'started_at' => $startedAt,
            'coin_rate_per_minute' => 1000,
        ]);
        for ($minute = 1; $minute <= 3; $minute++) {
            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'coins' => 1000,
                'category' => 'video_call',
                'reference' => "call_billing:{$call->id}:{$minute}",
                'balance_before' => 5000 - (($minute - 1) * 1000),
                'balance_after' => 5000 - ($minute * 1000),
            ]);
        }

        $event = [
            'event' => 'participant_left',
            'createdAt' => $startedAt->copy()->addMinute()->timestamp,
            'room' => ['name' => 'call_test_departure'],
            'participant' => ['identity' => (string) $caller->id],
        ];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $token = JWT::encode([
            'iss' => 'test-key',
            'sha256' => base64_encode(hash('sha256', $body, true)),
            'exp' => time() + 300,
        ], 'test-secret', 'HS256');

        $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => $token,
        ], $body)->assertOk();

        $this->assertDatabaseHas('call_sessions', [
            'id' => $call->id,
            'status' => 'ended',
            'end_reason' => 'caller_disconnected',
            'billable_minutes' => 1,
            'total_coins_charged' => 1000,
        ]);
        $this->assertSame(4000, (int) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference' => "call_billing_refund:{$call->id}",
            'type' => 'credit',
            'coins' => 2000,
        ]);
        $this->assertSame(0, app(BillingReconciliationService::class)->anomalies()['call_wallet_debit_total_mismatch']);

        $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => $token,
        ], $body)->assertOk();
        $this->assertSame(1, WalletTransaction::query()->where('reference', "call_billing_refund:{$call->id}")->count());
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        Config::set('services.livekit.api_key', 'test-key');
        Config::set('services.livekit.api_secret', 'test-secret');

        $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => 'invalid',
        ], '{"event":"participant_left"}')->assertUnauthorized();
    }
}
