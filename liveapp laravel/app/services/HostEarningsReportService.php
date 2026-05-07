<?php

namespace App\Services;

use App\Models\CallSession;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomPkBattle;
use App\Models\LiveRoomPkEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HostEarningsReportService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function payloadForHost(Host $host): array
    {
        $now = now(self::BUSINESS_TIMEZONE);

        return [
            'today' => $this->buildPeriodPayload(
                $host,
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                'Today'
            ),
            'current_week' => $this->buildPeriodPayload(
                $host,
                $now->copy()->startOfWeek(Carbon::MONDAY),
                $now->copy()->endOfWeek(Carbon::SUNDAY),
                'This Week'
            ),
            'last_week' => $this->buildPeriodPayload(
                $host,
                $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY),
                $now->copy()->subWeek()->endOfWeek(Carbon::SUNDAY),
                'Last Week'
            ),
        ];
    }

    private function buildPeriodPayload(Host $host, Carbon $from, Carbon $to, string $label): array
    {
        $callRows = CallSession::query()
            ->where('host_id', $host->id)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$from, $to])
            ->get();

        $giftRows = LiveRoomGiftEarningLedger::query()
            ->with('room:id,room_type')
            ->where('host_id', $host->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $rooms = LiveRoom::query()
            ->where('host_id', $host->id)
            ->whereNotNull('started_at')
            ->where(function ($query) use ($from, $to) {
                $query
                    ->whereBetween('started_at', [$from, $to])
                    ->orWhereBetween('ended_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to) {
                        $inner
                            ->where('started_at', '<=', $from)
                            ->where(function ($overlap) use ($to) {
                                $overlap
                                    ->whereNull('ended_at')
                                    ->orWhere('ended_at', '>=', $to);
                            });
                    });
            })
            ->get(['id', 'room_type', 'started_at', 'ended_at', 'status']);

        $pkBattles = LiveRoomPkBattle::query()
            ->where(function ($query) use ($host) {
                $query->where('host_a_id', $host->id)->orWhere('host_b_id', $host->id);
            })
            ->where(function ($query) use ($from, $to) {
                $query
                    ->whereBetween('started_at', [$from, $to])
                    ->orWhereBetween('ended_at', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to]);
            })
            ->get(['id', 'host_a_id', 'host_b_id', 'status', 'started_at', 'ended_at', 'created_at']);

        $pkCoins = (int) LiveRoomPkEvent::query()
            ->whereHas('battle', function ($query) use ($host) {
                $query->where('host_a_id', $host->id)->orWhere('host_b_id', $host->id);
            })
            ->whereBetween('created_at', [$from, $to])
            ->sum('coins');

        $callSummary = [
            'audio_minutes' => (int) $callRows->where('type', 'audio')->sum('billable_minutes'),
            'audio_earnings' => (int) $callRows->where('type', 'audio')->sum('host_earning'),
            'video_minutes' => (int) $callRows->where('type', 'video')->sum('billable_minutes'),
            'video_earnings' => (int) $callRows->where('type', 'video')->sum('host_earning'),
        ];

        $audioGiftCoins = 0;
        $audioGiftEarnings = 0;
        $videoGiftCoins = 0;
        $videoGiftEarnings = 0;
        foreach ($giftRows as $row) {
            $roomType = strtolower((string) optional($row->room)->room_type);
            if ($roomType === 'audio') {
                $audioGiftCoins += (int) $row->total_coins;
                $audioGiftEarnings += (int) $row->host_payout_coins;
            } else {
                $videoGiftCoins += (int) $row->total_coins;
                $videoGiftEarnings += (int) $row->host_payout_coins;
            }
        }

        $audioRoomMinutes = 0;
        $videoRoomMinutes = 0;
        foreach ($rooms as $room) {
            $minutes = $this->overlapMinutes(
                $room->started_at?->copy()?->timezone(self::BUSINESS_TIMEZONE),
                ($room->ended_at ?? now())->copy()->timezone(self::BUSINESS_TIMEZONE),
                $from,
                $to
            );
            if ($minutes <= 0) {
                continue;
            }
            if (strtolower((string) $room->room_type) === 'audio') {
                $audioRoomMinutes += $minutes;
            } else {
                $videoRoomMinutes += $minutes;
            }
        }

        $hostPct = (float) ($host->payout_percentage ?? 0);
        $pkEarnings = (int) floor(($pkCoins * $hostPct) / 100);

        return [
            'label' => $label,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'summary' => [
                'total_video_room_minutes' => $videoRoomMinutes,
                'total_audio_room_minutes' => $audioRoomMinutes,
                'total_gifted_coins' => $audioGiftCoins + $videoGiftCoins + $pkCoins,
                'total_room_gifts_coins' => $audioGiftCoins + $videoGiftCoins,
                'audio_room_gifts_coins' => $audioGiftCoins,
                'video_room_gifts_coins' => $videoGiftCoins,
                'audio_room_gift_earnings' => $audioGiftEarnings,
                'video_room_gift_earnings' => $videoGiftEarnings,
                'audio_call_minutes' => $callSummary['audio_minutes'],
                'audio_call_earnings' => $callSummary['audio_earnings'],
                'video_call_minutes' => $callSummary['video_minutes'],
                'video_call_earnings' => $callSummary['video_earnings'],
                'pk_room_count' => $pkBattles->count(),
                'pk_gift_coins' => $pkCoins,
                'pk_earnings' => $pkEarnings,
            ],
        ];
    }

    private function overlapMinutes(?Carbon $start, ?Carbon $end, Carbon $from, Carbon $to): int
    {
        if ($start === null || $end === null) {
            return 0;
        }

        $effectiveStart = $start->greaterThan($from) ? $start : $from;
        $effectiveEnd = $end->lessThan($to) ? $end : $to;

        if ($effectiveEnd->lessThanOrEqualTo($effectiveStart)) {
            return 0;
        }

        return (int) ceil($effectiveStart->diffInSeconds($effectiveEnd) / 60);
    }
}
