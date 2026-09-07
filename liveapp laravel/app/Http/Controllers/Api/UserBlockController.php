<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class UserBlockController extends Controller
{
    public function __construct(private UserBlockService $blocks) {}

    public function index(Request $request)
    {
        $rows = $this->blocks->blockedUsersQuery($request->user())->get();

        return response()->json([
            'ok' => true,
            'data' => $rows
                ->map(fn ($row) => $this->blocks->payload($row))
                ->values(),
            'meta' => ['total' => $rows->count()],
        ]);
    }

    public function store(Request $request, User $user)
    {
        try {
            $block = $this->blocks->block($request->user(), $user);
        } catch (InvalidArgumentException $error) {
            return response()->json(['ok' => false, 'msg' => $error->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->blocks->payload($block),
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $this->blocks->unblock($request->user(), $user);

        return response()->json(['ok' => true]);
    }
}
