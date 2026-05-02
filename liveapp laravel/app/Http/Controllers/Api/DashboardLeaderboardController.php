<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LeaderboardService;
use Illuminate\Http\Request;

class DashboardLeaderboardController extends Controller
{
    public function __construct(private LeaderboardService $leaderboards)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => 'nullable|in:users,hosts,agencies,all',
            'period' => 'nullable|in:alltime,weekly',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->leaderboards->payload(
                $data['type'] ?? 'all',
                $data['period'] ?? 'weekly',
                (int) ($data['limit'] ?? 10),
            ),
        ]);
    }
}
