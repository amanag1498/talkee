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

    public function editGames()
    {
        return view('admin.settings.games', [
            'definitions' => AppSettingsService::GAME_DEFINITIONS,
            'values' => $this->settings->gameSettings(),
            'groups' => [
                'availability' => 'Availability',
                'limits' => 'Bet Limits',
                'timing' => 'Round Timing',
                'economy' => 'Economy and Winner Selection',
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

            if ($type === 'integer') {
                $parts = ['required', 'integer'];
                if (array_key_exists('min', $definition)) {
                    $parts[] = 'min:' . $definition['min'];
                }
                if (array_key_exists('max', $definition)) {
                    $parts[] = 'max:' . $definition['max'];
                }
                $rules[$key] = implode('|', $parts);
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
            if (($definition['type'] ?? 'integer') === 'boolean') {
                $rules[$key] = 'required|boolean';
                continue;
            }

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

    public function updateGames(Request $request)
    {
        $selectedGame = $request->query('game', 'teen_patti');
        if (!in_array($selectedGame, ['teen_patti', 'greedy'], true)) {
            $selectedGame = 'teen_patti';
        }

        $selectedPrefix = "games.{$selectedGame}.";
        $rules = [];
        foreach (AppSettingsService::GAME_DEFINITIONS as $key => $definition) {
            if (!str_starts_with($key, $selectedPrefix)) {
                continue;
            }

            $type = $definition['type'] ?? 'boolean';
            if ($type === 'boolean') {
                $rules[$key] = 'required|boolean';
                continue;
            }

            if ($type === 'string' && !empty($definition['options'])) {
                $rules[$key] = 'required|string|in:' . implode(',', $definition['options']);
                continue;
            }

            $parts = ['required', $type === 'float' ? 'numeric' : 'integer'];
            if (array_key_exists('min', $definition)) {
                $parts[] = 'min:' . $definition['min'];
            }
            if (array_key_exists('max', $definition)) {
                $parts[] = 'max:' . $definition['max'];
            }
            $rules[$key] = implode('|', $parts);
        }

        $validated = $request->validate($rules);
        $games = $validated['games'];

        if ($selectedGame === 'teen_patti' && (int) data_get($games, 'teen_patti.max_bet') < (int) data_get($games, 'teen_patti.min_bet')) {
            throw ValidationException::withMessages([
                'games.teen_patti.max_bet' => 'Maximum bet must be greater than or equal to minimum bet.',
            ]);
        }

        if ($selectedGame === 'teen_patti' && (int) data_get($games, 'teen_patti.betting_lock_seconds') >= (int) data_get($games, 'teen_patti.round_duration_seconds')) {
            throw ValidationException::withMessages([
                'games.teen_patti.betting_lock_seconds' => 'Bet lock seconds must be less than round duration seconds.',
            ]);
        }

        if ($selectedGame === 'greedy' && (int) data_get($games, 'greedy.max_bet') < (int) data_get($games, 'greedy.min_bet')) {
            throw ValidationException::withMessages([
                'games.greedy.max_bet' => 'Greedy maximum bet must be greater than or equal to minimum bet.',
            ]);
        }

        if ($selectedGame === 'greedy' && (int) data_get($games, 'greedy.betting_lock_seconds') >= (int) data_get($games, 'greedy.round_duration_seconds')) {
            throw ValidationException::withMessages([
                'games.greedy.betting_lock_seconds' => 'Greedy bet lock seconds must be less than round duration seconds.',
            ]);
        }

        $this->settings->updateGameSettings($games);

        return redirect()
            ->route('admin.settings.games.edit', ['game' => $selectedGame])
            ->with('ok', 'Game settings updated.');
    }
}
