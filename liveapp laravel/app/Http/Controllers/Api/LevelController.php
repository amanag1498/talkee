<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserLevelService;

class LevelController extends Controller
{
    public function __construct(private UserLevelService $levels)
    {
    }

    public function index()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->levels->activeLevels()->map(fn ($level) => [
                'id' => $level->id,
                'level' => $level->level,
                'title' => $level->title,
                'min_spend_coins' => (int) $level->min_spend_coins,
                'badge_icon' => $level->badge_icon,
                'badge_color' => $level->badge_color,
                'benefits' => $level->benefits ?? [],
                'is_active' => (bool) $level->is_active,
                'sort_order' => (int) $level->sort_order,
            ])->values()->all(),
        ]);
    }
}
