<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function __construct(private AppSettingsService $settings)
    {
    }

    public function editCalls()
    {
        return view('admin.settings.calls', [
            'definitions' => AppSettingsService::CALL_DEFINITIONS,
            'values' => $this->settings->callSettings(),
            'legacyFallbackRate' => (int) env('CALLS_COIN_RATE_PER_MINUTE', 20),
        ]);
    }

    public function updateCalls(Request $request)
    {
        $rules = [];
        foreach (AppSettingsService::CALL_DEFINITIONS as $key => $definition) {
            $numericRule = $definition['type'] === 'float' ? 'numeric' : 'integer';
            $parts = ['required', $numericRule];
            if (array_key_exists('min', $definition)) {
                $parts[] = 'min:' . $definition['min'];
            }
            if (array_key_exists('max', $definition)) {
                $parts[] = 'max:' . $definition['max'];
            }
            $rules[$key] = implode('|', $parts);
        }

        $validated = $request->validate($rules);

        $callSettings = $validated['calls'];

        $shareTotal = (float) $callSettings['host_share_percent']
            + (float) $callSettings['agency_share_percent']
            + (float) $callSettings['platform_share_percent'];

        if (abs($shareTotal - 100.0) > 0.0001) {
            throw ValidationException::withMessages([
                'calls.platform_share_percent' => 'Host, agency, and platform share must total exactly 100%.',
            ]);
        }

        $this->settings->updateCallSettings($callSettings);

        return redirect()
            ->route('admin.settings.calls.edit')
            ->with('ok', 'Call settings updated.');
    }

    public function editLiveRooms()
    {
        return view('admin.settings.live-rooms', [
            'definitions' => AppSettingsService::LIVE_ROOM_DEFINITIONS,
            'values' => $this->settings->liveRoomSettings(),
        ]);
    }

    public function editApp()
    {
        return view('admin.settings.app', [
            'definitions' => AppSettingsService::APP_DEFINITIONS,
            'values' => $this->settings->appSettings(),
            'groups' => [
                'general' => 'Global App Controls',
                'host_goals' => 'Host Goal Milestones',
                'android' => 'Android Feature Flags',
            ],
        ]);
    }

    public function updateApp(Request $request)
    {
        $rules = [];
        foreach (AppSettingsService::APP_DEFINITIONS as $key => $definition) {
            $type = $definition['type'] ?? 'boolean';
            if ($type === 'string' && !empty($definition['options'])) {
                $options = implode(',', $definition['options'] ?? []);
                $rules[$key] = 'required|string|in:' . $options;
                continue;
            }

            if ($type === 'string') {
                $rules[$key] = 'required|string';
                continue;
            }

            if ($type === 'csv_integer_list') {
                $rules[$key] = ['required', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'];
                continue;
            }

            $rules[$key] = 'required|boolean';
        }

        $validated = $request->validate($rules);
        $this->settings->updateAppSettings($validated['app_features']);

        return redirect()
            ->route('admin.settings.app.edit')
            ->with('ok', 'App settings updated.');
    }

    public function updateLiveRooms(Request $request)
    {
        $rules = [];
        foreach (AppSettingsService::LIVE_ROOM_DEFINITIONS as $key => $definition) {
            $parts = ['required', 'integer'];
            if (array_key_exists('min', $definition)) {
                $parts[] = 'min:' . $definition['min'];
            }
            if (array_key_exists('max', $definition)) {
                $parts[] = 'max:' . $definition['max'];
            }
            $rules[$key] = implode('|', $parts);
        }

        $validated = $request->validate($rules);
        $roomSettings = $validated['live_rooms'];

        if ((int) data_get($roomSettings, 'video.max_speakers') >= (int) data_get($roomSettings, 'video.max_participants')) {
            throw ValidationException::withMessages([
                'live_rooms.video.max_speakers' => 'Video max speakers must be less than video max participants.',
            ]);
        }

        if ((int) data_get($roomSettings, 'audio.max_speakers') >= (int) data_get($roomSettings, 'audio.max_participants')) {
            throw ValidationException::withMessages([
                'live_rooms.audio.max_speakers' => 'Audio max speakers must be less than audio max participants.',
            ]);
        }

        $this->settings->updateLiveRoomSettings($roomSettings);

        return redirect()
            ->route('admin.settings.live-rooms.edit')
            ->with('ok', 'Live room settings updated.');
    }
}
