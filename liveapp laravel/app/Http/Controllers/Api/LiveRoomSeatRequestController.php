<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveRoom;
use App\Models\LiveRoomSeatRequest;
use App\Models\User;
use App\Services\LiveRoomSeatService;
use Illuminate\Http\Request;

class LiveRoomSeatRequestController extends Controller
{
    public function __construct(private LiveRoomSeatService $seats)
    {
    }

    public function store(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $seatRequest = $this->seats->requestSeat($room, $request->user());

        return response()->json([
            'ok' => true,
            'request_id' => $seatRequest->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }

    public function index(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();

        return response()->json([
            'ok' => true,
            'data' => $this->seats->snapshot($room, $request->user(), [
                'status' => $request->string('status')->trim()->toString(),
            ]),
        ]);
    }

    public function accept(Request $request, string $room_id, int $id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $seatRequest = LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->findOrFail($id);

        $updated = $this->seats->acceptRequest($room, $seatRequest, $request->user());

        return response()->json([
            'ok' => true,
            'request_id' => $updated->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }

    public function reject(Request $request, string $room_id, int $id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $seatRequest = LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->findOrFail($id);

        $updated = $this->seats->rejectRequest($room, $seatRequest, $request->user(), $request->input('reason'));

        return response()->json([
            'ok' => true,
            'request_id' => $updated->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }

    public function cancel(Request $request, string $room_id, int $id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $seatRequest = LiveRoomSeatRequest::query()
            ->where('live_room_id', $room->id)
            ->whereKey($id)
            ->first();

        if ($seatRequest) {
            abort_unless((int) $seatRequest->user_id === (int) $request->user()->id, 403, 'You can only cancel your own request.');
        }

        $updated = $this->seats->cancelRequest($room, $request->user());

        return response()->json([
            'ok' => true,
            'request_id' => $updated?->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }

    public function removeSpeaker(Request $request, string $room_id, int $user_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $target = User::query()->findOrFail($user_id);
        $result = $this->seats->removeSpeaker($room, $target, $request->user(), $request->input('reason'));

        return response()->json([
            'ok' => true,
            'request_id' => $result['request']?->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }

    public function speakers(Request $request, string $room_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $snapshot = $this->seats->snapshot($room, $request->user());

        return response()->json([
            'ok' => true,
            'data' => [
                'room_id' => $snapshot['room_id'],
                'speaker_count' => $snapshot['speaker_count'],
                'max_speakers' => $snapshot['max_speakers'],
                'speakers' => $snapshot['speakers'],
            ],
        ]);
    }

    public function muteSpeaker(Request $request, string $room_id, int $user_id)
    {
        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();
        $target = User::query()->findOrFail($user_id);
        $result = $this->seats->muteSpeaker($room, $target, $request->user());

        return response()->json([
            'ok' => true,
            'participant_id' => $result['participant']?->id,
            'snapshot' => $this->seats->snapshot($room, $request->user()),
        ]);
    }
}
