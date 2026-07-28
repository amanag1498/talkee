<?php

namespace App\Services;

use App\Models\MetaAppEvent;
use App\Models\PaymentOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MetaAppEventRecorder
{
    public function record(
        string $eventName,
        ?User $user = null,
        array $attributes = [],
        ?Request $request = null,
    ): ?MetaAppEvent {
        if (! $this->schemaReady()) {
            return null;
        }

        try {
            $eventId = $attributes['event_id'] ?? (string) Str::uuid();

            return MetaAppEvent::query()->firstOrCreate(
                ['event_id' => $eventId],
                [
                    'user_id' => $user?->id,
                    'payment_order_id' => $attributes['payment_order_id'] ?? null,
                    'event_name' => $eventName,
                    'source' => $attributes['source'] ?? 'app',
                    'platform' => $attributes['platform'] ?? null,
                    'app_version' => $attributes['app_version'] ?? null,
                    'advertiser_tracking_enabled' => $attributes['advertiser_tracking_enabled'] ?? null,
                    'value' => $attributes['value'] ?? null,
                    'currency' => $attributes['currency'] ?? null,
                    'properties' => $attributes['properties'] ?? null,
                    'ip' => $request?->ip(),
                    'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
                    'occurred_at' => $attributes['occurred_at'] ?? now(),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($eventName, $exception);

            return null;
        }
    }

    public function recordVerifiedPurchase(PaymentOrder $order, ?Request $request = null): ?MetaAppEvent
    {
        if (! $this->schemaReady()) {
            return null;
        }

        try {
            return MetaAppEvent::query()->firstOrCreate(['payment_order_id' => $order->id], [
                'user_id' => $order->user_id,
                'event_id' => (string) Str::uuid(),
                'event_name' => 'purchase',
                'source' => 'server',
                'value' => $order->amount_rupees,
                'currency' => 'INR',
                'properties' => [
                    'order_id' => $order->order_id,
                    'gateway' => $order->gateway,
                    'coins' => (int) $order->total_coins,
                ],
                'occurred_at' => $order->verified_at ?? now(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            ]);
        } catch (Throwable $exception) {
            $this->logFailure('purchase', $exception);

            return null;
        }
    }

    private function schemaReady(): bool
    {
        try {
            $ready = Schema::hasTable('meta_app_events');
        } catch (Throwable $exception) {
            $this->logFailure('schema_check', $exception);

            return false;
        }

        if (! $ready) {
            Log::warning('META_APP_EVENTS_TABLE_MISSING', [
                'action' => 'Run php artisan migrate --force',
            ]);
        }

        return $ready;
    }

    private function logFailure(string $eventName, Throwable $exception): void
    {
        Log::warning('META_APP_EVENT_AUDIT_FAILED', [
            'event_name' => $eventName,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
