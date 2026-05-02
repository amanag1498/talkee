<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\LiveRoom;
use App\Services\LiveRoomGiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveRoomGiftController extends Controller
{
    public function __construct(private LiveRoomGiftService $gifts)
    {
    }

    public function index()
    {
        return response()->json([
            'ok' => true,
            'data' => $this->gifts->availableGifts(),
        ]);
    }

    public function store(Request $request, string $room_id)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $room = LiveRoom::query()->where('room_id', $room_id)->firstOrFail();

        $data = $request->validate([
            'gift_id' => ['required', 'integer', 'exists:gifts,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'message' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $gift = Gift::query()->whereKey($data['gift_id'])->firstOrFail();
            $result = $this->gifts->send(
                room: $room,
                sender: $user,
                gift: $gift,
                quantity: (int) ($data['quantity'] ?? 1),
                message: $data['message'] ?? null,
            );

            return response()->json([
                'ok' => true,
                ...$result,
            ], 201);
        } catch (HttpException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'Insufficient')) {
                return response()->json([
                    'ok' => false,
                    'error' => 'INSUFFICIENT_FUNDS',
                    'message' => 'Not enough coins for this gift.',
                ], 402);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('LIVE_ROOM_GIFT_SEND_FAIL', [
                'room_id' => $room_id,
                'user_id' => $user->id,
                'gift_id' => $data['gift_id'] ?? null,
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'SERVER_ERROR',
                'message' => 'Unable to send gift right now.',
            ], 500);
        }
    }
}
