<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetaAppEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetaAppEventController extends Controller
{
    private const APP_EVENTS = [
        'app_launch',
        'login',
        'advertiser_tracking_consent',
    ];

    public function store(Request $request, MetaAppEventRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'event_name' => ['required', Rule::in(self::APP_EVENTS)],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:40'],
            'advertiser_tracking_enabled' => ['nullable', 'boolean'],
            'properties' => ['nullable', 'array'],
        ]);

        $event = $recorder->record($data['event_name'], $request->user(), [
            ...$data,
            'properties' => $this->safeProperties($data['properties'] ?? []),
        ], $request);

        if (! $event) {
            return response()->json([
                'ok' => false,
                'message' => 'Meta event auditing is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'data' => ['event_id' => $event->event_id],
        ], 201);
    }

    private function safeProperties(array $properties): array
    {
        return collect($properties)
            ->only(['login_provider', 'consent_status'])
            ->map(fn ($value) => is_scalar($value) ? (string) $value : null)
            ->filter(fn ($value) => $value !== null)
            ->all();
    }
}
