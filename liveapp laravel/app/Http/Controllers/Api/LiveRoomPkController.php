<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Services\LiveRoomPkService;
use Illuminate\Http\Request;

class LiveRoomPkController extends Controller
{
    public function __construct(private LiveRoomPkService $pk)
    {
    }

    public function invite(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $data = $request->validate([
            'target_room_id' => 'required|string',
            'duration_seconds' => 'nullable|integer|min:60|max:900',
        ]);

        $targetRoom = LiveRoom::query()->where('room_id', $data['target_room_id'])->firstOrFail();
        $battle = $this->pk->invite(
            $room,
            $targetRoom,
            $request->user(),
            array_key_exists('duration_seconds', $data) ? (int) $data['duration_seconds'] : null,
        );

        return response()->json(['ok' => true, 'data' => $this->pk->payload($battle)]);
    }

    public function accept(Request $request, string $room_id, string $battle_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->accept($room, $battle_id, $request->user());

        return response()->json(['ok' => true, 'data' => $this->pk->payload($battle)]);
    }

    public function reject(Request $request, string $room_id, string $battle_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->reject($room, $battle_id, $request->user());

        return response()->json(['ok' => true, 'data' => $this->pk->payload($battle)]);
    }

    public function cancel(Request $request, string $room_id, string $battle_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->cancel($room, $battle_id, $request->user());

        return response()->json(['ok' => true, 'data' => $this->pk->payload($battle)]);
    }

    public function end(Request $request, string $room_id, string $battle_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->end($room, $battle_id, $request->user());

        return response()->json(['ok' => true, 'data' => $this->pk->payload($battle)]);
    }

    public function active(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->activeForRoom($room);

        return response()->json([
            'ok' => true,
            'data' => $this->pk->payload($battle),
        ]);
    }

    public function history(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $history = $this->pk->historyForRoom($room);

        return response()->json([
            'ok' => true,
            'data' => collect($history->items())->map(fn ($battle) => $this->pk->payload($battle))->values(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'per_page' => $history->perPage(),
                'has_more' => $history->hasMorePages(),
                'total' => $history->total(),
            ],
        ]);
    }

    public function mediaToken(Request $request, string $room_id, string $battle_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $battle = $this->pk->activeForRoom($room);
        abort_unless($battle && $battle->battle_id === $battle_id, 404, 'Active PK battle not found.');

        return response()->json([
            'ok' => true,
            'data' => $this->pk->mediaToken($room, $battle, $request->user(), $request),
        ]);
    }
}
