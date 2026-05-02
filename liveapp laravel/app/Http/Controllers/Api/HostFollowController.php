<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Models\User;
use App\Services\HostFollowService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class HostFollowController extends Controller
{
    public function __construct(private HostFollowService $follows)
    {
    }

    public function follow(Request $request, Host $host)
    {
        try {
            $follow = $this->follows->follow($request->user(), $host, [
                'notify_when_online' => $request->boolean('notify_when_online', true),
                'notify_when_available' => $request->boolean('notify_when_available', true),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $follow->id,
                ...$this->follows->followState($request->user(), $host),
            ],
        ]);
    }

    public function unfollow(Request $request, Host $host)
    {
        $this->follows->unfollow($request->user(), $host);

        return response()->json([
            'ok' => true,
            'data' => $this->follows->followState($request->user(), $host),
        ]);
    }

    public function state(Request $request, Host $host)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->follows->followState($request->user(), $host),
        ]);
    }

    public function stateByUser(Request $request, User $user)
    {
        $host = $user->host;
        if (!$host) {
            return response()->json(['ok' => false, 'msg' => 'Host profile not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $this->follows->followState($request->user(), $host),
        ]);
    }

    public function following(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->follows->followingList($request->user()),
        ]);
    }

    public function followers(Request $request)
    {
        try {
            $data = $this->follows->followersList($request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], 403);
        }

        return response()->json([
            'ok' => true,
            'data' => $data,
        ]);
    }
}
