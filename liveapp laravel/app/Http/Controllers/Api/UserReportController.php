<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserReport;
use App\Services\ModerationService;
use Illuminate\Http\Request;

class UserReportController extends Controller
{
    private const REASON_TYPES = [
        'abuse',
        'spam',
        'harassment',
        'scam',
        'nudity',
        'hate_speech',
        'other',
    ];

    public function __construct(private ModerationService $moderation)
    {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'reported_user_id' => 'required|integer|exists:users,id',
            'host_user_id' => 'nullable|integer|exists:users,id',
            'room_id' => 'nullable|string|max:64',
            'room_type' => 'nullable|in:audio,video',
            'reason_type' => 'required|in:'.implode(',', self::REASON_TYPES),
            'description' => 'nullable|string|max:1000',
        ]);

        $report = $this->moderation->createReport($user, $data);

        return response()->json(['ok' => true, 'data' => $report], 201);
    }

    public function mine(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $rows = UserReport::query()
            ->with(['reportedUser', 'hostUser', 'reviewer'])
            ->where('reporter_user_id', $user->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'ok' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'has_more' => $rows->hasMorePages(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
