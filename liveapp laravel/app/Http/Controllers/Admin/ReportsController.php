<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\LiveRoomParticipant;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DateInterval;
use DatePeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function hosts(Request $request)
    {
        $hostId = $request->integer('host_id');
        $range = $request->input('range', 'daily');
        $from = ($request->date('from') ?: now()->subDays(6))->startOfDay();
        $to = ($request->date('to') ?: now())->endOfDay();

        $hosts = Host::with(['user', 'agency'])->orderBy('id', 'desc')->limit(500)->get();
        $hostsById = $hosts->keyBy('id');

        $rooms = LiveRoom::query()
            ->when($hostId, fn ($query) => $query->where('host_id', $hostId))
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $to)
            ->whereRaw('COALESCE(ended_at, last_activity_at, started_at) >= ?', [$from->toDateTimeString()])
            ->get(['id', 'host_id', 'started_at', 'ended_at', 'last_activity_at']);

        $roomIds = $rooms->pluck('id');
        $reportHostIds = $hostId
            ? collect([$hostId])
            : $hosts->pluck('id')->unique()->values();

        $giftAgg = $this->regularGiftLedgerBase($from, $to)
            ->when($roomIds->isNotEmpty(), fn ($query) => $query->whereIn('live_room_gift_earning_ledgers.live_room_id', $roomIds))
            ->selectRaw('
                live_room_gift_earning_ledgers.host_id as host_id,
                DATE(live_room_gift_earning_ledgers.created_at) as d,
                SUM(live_room_gift_earning_ledgers.total_coins) as gift_coins,
                SUM(live_room_gift_earning_ledgers.host_payout_coins) as host_payable,
                SUM(live_room_gift_earning_ledgers.agency_payout_coins) as agency_payable,
                COUNT(*) as gift_events
            ')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

        $pkAgg = LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->when($roomIds->isNotEmpty(), fn ($query) => $query->whereIn('live_room_gift_earning_ledgers.live_room_id', $roomIds))
            ->selectRaw('
                live_room_gift_earning_ledgers.host_id as host_id,
                DATE(live_room_gift_earning_ledgers.created_at) as d,
                SUM(live_room_gift_earning_ledgers.total_coins) as pk_coins,
                SUM(live_room_gift_earning_ledgers.host_payout_coins) as host_payable,
                SUM(live_room_gift_earning_ledgers.agency_payout_coins) as agency_payable,
                COUNT(live_room_pk_events.id) as pk_events
            ')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

        $callAgg = $this->successfulCallLedgerBase($from, $to)
            ->when($hostId, fn ($query) => $query->where('call_earning_ledgers.host_id', $hostId))
            ->whereNotNull('call_earning_ledgers.host_id')
            ->selectRaw('
                call_earning_ledgers.host_id as host_id,
                DATE(call_earning_ledgers.created_at) as d,
                SUM(call_earning_ledgers.total_coins) as call_coins,
                SUM(call_earning_ledgers.host_earning) as host_payable,
                SUM(call_earning_ledgers.agency_earning) as agency_payable,
                COUNT(*) as call_count
            ')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

        $partAgg = LiveRoomParticipant::query()
            ->when($roomIds->isNotEmpty(), fn ($query) => $query->whereIn('live_room_id', $roomIds))
            ->selectRaw('live_rooms.host_id as host_id,
                         DATE(live_room_participants.joined_at) as d,
                         COUNT(*) as participants_total,
                         COUNT(DISTINCT COALESCE(CAST(user_id AS CHAR), CONCAT("sess:", session_id))) as participants_unique')
            ->join('live_rooms', 'live_rooms.id', '=', 'live_room_participants.live_room_id')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

        $durAgg = collect();
        foreach ($rooms as $room) {
            $roomStart = $room->started_at?->copy();
            $roomEnd = ($room->ended_at ?? $room->last_activity_at ?? $room->started_at)?->copy();
            if (!$roomStart || !$roomEnd) {
                continue;
            }

            $effectiveStart = $roomStart->greaterThan($from) ? $roomStart : $from->copy();
            $effectiveEnd = $roomEnd->lessThan($to) ? $roomEnd : $to->copy();
            if ($effectiveEnd->lessThanOrEqualTo($effectiveStart)) {
                continue;
            }

            $dayCursor = $effectiveStart->copy()->startOfDay();
            $lastDay = $effectiveEnd->copy()->startOfDay();

            while ($dayCursor->lessThanOrEqualTo($lastDay)) {
                $dayStart = $dayCursor->copy();
                $dayEnd = $dayCursor->copy()->endOfDay();
                $segmentStart = $effectiveStart->greaterThan($dayStart) ? $effectiveStart : $dayStart;
                $segmentEnd = $effectiveEnd->lessThan($dayEnd) ? $effectiveEnd : $dayEnd;

                if ($segmentEnd->greaterThan($segmentStart)) {
                    $key = $dayCursor->format('Y-m-d');
                    $hostBucket = $durAgg->get($key, collect());
                    $existing = $hostBucket->firstWhere('host_id', $room->host_id);

                    $segmentSeconds = $segmentStart->diffInSeconds($segmentEnd);
                    if ($segmentSeconds <= 0) {
                        $dayCursor->addDay();
                        continue;
                    }

                    if ($existing) {
                        $existing->duration_seconds += $segmentSeconds;
                        $existing->rooms += 1;
                    } else {
                        $hostBucket->push((object) [
                            'host_id' => $room->host_id,
                            'duration_seconds' => $segmentSeconds,
                            'rooms' => 1,
                        ]);
                    }

                    $durAgg->put($key, $hostBucket);
                }

                $dayCursor->addDay();
            }
        }

        $days = collect();
        $period = new DatePeriod($from->copy()->startOfDay(), new DateInterval('P1D'), $to->copy()->endOfDay()->addDay());

        foreach ($period as $dt) {
            $key = $dt->format('Y-m-d');
            foreach ($reportHostIds as $hid) {
                $host = $hostsById->get($hid);
                if (!$host) {
                    continue;
                }

                $gift = optional($giftAgg->get($key))->firstWhere('host_id', $hid);
                $pk = optional($pkAgg->get($key))->firstWhere('host_id', $hid);
                $call = optional($callAgg->get($key))->firstWhere('host_id', $hid);
                $participants = optional($partAgg->get($key))->firstWhere('host_id', $hid);
                $duration = optional($durAgg->get($key))->firstWhere('host_id', $hid);

                $callCoins = (int) ($call->call_coins ?? 0);
                $giftCoins = (int) ($gift->gift_coins ?? 0);
                $pkCoins = (int) ($pk->pk_coins ?? 0);
                $grossCoins = $callCoins + $giftCoins + $pkCoins;
                $hostPayable = (int) ($call->host_payable ?? 0) + (int) ($gift->host_payable ?? 0) + (int) ($pk->host_payable ?? 0);
                $agencyPayable = (int) ($call->agency_payable ?? 0) + (int) ($gift->agency_payable ?? 0) + (int) ($pk->agency_payable ?? 0);
                $hostPct = $grossCoins > 0 ? round(($hostPayable / $grossCoins) * 100, 2) : 0.0;
                $agencyPct = $grossCoins > 0 ? round(($agencyPayable / $grossCoins) * 100, 2) : 0.0;

                $days->push([
                    'date' => $key,
                    'host_id' => $hid,
                    'rooms' => (int) ($duration->rooms ?? 0),
                    'duration_seconds' => (int) ($duration->duration_seconds ?? 0),
                    'duration_min' => (int) floor(((int) ($duration->duration_seconds ?? 0)) / 60),
                    'participants_total' => (int) ($participants->participants_total ?? 0),
                    'participants_unique' => (int) ($participants->participants_unique ?? 0),
                    'call_coins' => $callCoins,
                    'call_count' => (int) ($call->call_count ?? 0),
                    'gift_coins' => $giftCoins,
                    'gift_events' => (int) ($gift->gift_events ?? 0),
                    'pk_coins' => $pkCoins,
                    'pk_events' => (int) ($pk->pk_events ?? 0),
                    'gross_coins' => $grossCoins,
                    'host_payout_percentage' => $hostPct,
                    'agency_payout_percentage' => $agencyPct,
                    'host_weekly_bonus' => 0,
                    'host_payable' => $hostPayable,
                    'agency_payable' => $agencyPayable,
                ]);
            }
        }

        $rows = $days;

        if ($range === 'weekly') {
            $rows = $rows
                ->groupBy(fn ($row) => Carbon::parse($row['date'])->startOfWeek(Carbon::MONDAY)->format('Y-m-d'))
                ->flatMap(function ($weekRows, $weekStart) use ($hostsById, $to) {
                    return $weekRows->groupBy('host_id')->map(function ($group) use ($weekStart, $hostsById, $to) {
                        $grossCoins = (int) $group->sum('gross_coins');
                        $hostPayable = (int) $group->sum('host_payable');
                        $agencyPayable = (int) $group->sum('agency_payable');
                        $hostPct = $grossCoins > 0 ? round(($hostPayable / $grossCoins) * 100, 2) : 0.0;
                        $agencyPct = $grossCoins > 0 ? round(($agencyPayable / $grossCoins) * 100, 2) : 0.0;
                        $weekFrom = Carbon::parse($weekStart)->startOfDay();
                        $weekTo = $weekFrom->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()->min($to);
                        $roomMetrics = $this->roomMetrics($weekFrom, $weekTo, (int) $group->first()['host_id']);

                        return [
                            'week_start' => $weekStart,
                            'host_id' => $group->first()['host_id'],
                            'rooms' => (int) $roomMetrics['count'],
                            'duration_min' => (int) $roomMetrics['minutes'],
                            'participants_total' => (int) $group->sum('participants_total'),
                            'participants_unique' => (int) $group->sum('participants_unique'),
                            'call_coins' => (int) $group->sum('call_coins'),
                            'call_count' => (int) $group->sum('call_count'),
                            'gift_coins' => (int) $group->sum('gift_coins'),
                            'gift_events' => (int) $group->sum('gift_events'),
                            'pk_coins' => (int) $group->sum('pk_coins'),
                            'pk_events' => (int) $group->sum('pk_events'),
                            'gross_coins' => $grossCoins,
                            'host_payout_percentage' => $hostPct,
                            'agency_payout_percentage' => $agencyPct,
                            'host_weekly_bonus' => 0,
                            'host_payable' => $hostPayable,
                            'agency_payable' => $agencyPayable,
                        ];
                    })->values();
                })
                ->values();
        } else {
            $rows = $rows->values();
        }

        return view('admin.reports.hosts', [
            'rows' => $rows,
            'hosts' => $hosts,
            'hostId' => $hostId,
            'range' => $range,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function hostsCsv(Request $request): StreamedResponse
    {
        $request->merge(['range' => $request->input('range', 'daily')]);
        $view = $this->hosts($request);
        $data = $view->getData();
        $rows = collect($data['rows']);

        $filename = 'host-report-' . $data['range'] . '-' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($rows, $data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $data['range'] === 'weekly'
                ? ['week_start', 'host_id', 'rooms', 'duration_min', 'participants_total', 'participants_unique', 'call_coins', 'call_count', 'gift_coins', 'gift_events', 'pk_coins', 'pk_events', 'gross_coins', 'host_payout_percentage', 'host_weekly_bonus', 'host_payable', 'agency_payout_percentage', 'agency_payable']
                : ['date', 'host_id', 'rooms', 'duration_min', 'participants_total', 'participants_unique', 'call_coins', 'call_count', 'gift_coins', 'gift_events', 'pk_coins', 'pk_events', 'gross_coins', 'host_payout_percentage', 'host_payable', 'agency_payout_percentage', 'agency_payable']
            );

            foreach ($rows as $row) {
                if ($data['range'] === 'weekly') {
                    fputcsv($out, [
                        $row['week_start'],
                        $row['host_id'],
                        $row['rooms'],
                        $row['duration_min'],
                        $row['participants_total'],
                        $row['participants_unique'],
                        $row['call_coins'],
                        $row['call_count'],
                        $row['gift_coins'],
                        $row['gift_events'],
                        $row['pk_coins'],
                        $row['pk_events'],
                        $row['gross_coins'],
                        $row['host_payout_percentage'],
                        $row['host_weekly_bonus'],
                        $row['host_payable'],
                        $row['agency_payout_percentage'],
                        $row['agency_payable'],
                    ]);
                } else {
                    fputcsv($out, [
                        $row['date'],
                        $row['host_id'],
                        $row['rooms'],
                        $row['duration_min'],
                        $row['participants_total'],
                        $row['participants_unique'],
                        $row['call_coins'],
                        $row['call_count'],
                        $row['gift_coins'],
                        $row['gift_events'],
                        $row['pk_coins'],
                        $row['pk_events'],
                        $row['gross_coins'],
                        $row['host_payout_percentage'],
                        $row['host_payable'],
                        $row['agency_payout_percentage'],
                        $row['agency_payable'],
                    ]);
                }
            }
            fclose($out);
        }, 200, $headers);
    }

    public function hostShow(Host $host, Request $request)
    {
        $from = ($request->date('from') ?: now()->subDays(6))->startOfDay();
        $to = ($request->date('to') ?: now())->endOfDay();

        $host->load(['user', 'agency', 'followers.user']);

        $callBase = CallSession::query()
            ->with(['caller', 'receiver', 'agency'])
            ->where('host_id', $host->id)
            ->whereBetween('created_at', [$from, $to]);
        $callLedgerBase = $this->successfulCallLedgerBase($from, $to)
            ->where('call_earning_ledgers.host_id', $host->id);

        $liveBase = $this->roomOverlapBase($from, $to, $host->id);

        $giftBase = $this->regularGiftLedgerBase($from, $to)
            ->where('live_room_gift_earning_ledgers.host_id', $host->id);
        $pkBase = $this->pkGiftLedgerBase($from, $to)
            ->where('live_room_gift_earning_ledgers.host_id', $host->id);

        $roomIds = (clone $liveBase)->pluck('id');
        $participantsBase = LiveRoomParticipant::query()->whereIn('live_room_id', $roomIds);

        $weeklyBreakdown = collect(CarbonPeriod::create($from->copy()->startOfWeek(Carbon::MONDAY), '1 week', $to))
            ->map(function ($weekStart) use ($host, $to) {
                $weekFrom = $weekStart->copy()->startOfDay();
                $weekTo = $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()->min($to);

                $calls = CallSession::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$weekFrom, $weekTo]);
                $callLedger = $this->successfulCallLedgerBase($weekFrom, $weekTo)
                    ->where('call_earning_ledgers.host_id', $host->id);
                $liveRooms = $this->roomOverlapBase($weekFrom, $weekTo, $host->id);
                $liveGifts = $this->regularGiftLedgerBase($weekFrom, $weekTo)
                    ->where('live_room_gift_earning_ledgers.host_id', $host->id);
                $pkGifts = $this->pkGiftLedgerBase($weekFrom, $weekTo)
                    ->where('live_room_gift_earning_ledgers.host_id', $host->id);
                $roomMetrics = $this->roomMetrics($weekFrom, $weekTo, $host->id);

                return [
                    'week_start' => $weekFrom->format('Y-m-d'),
                    'calls' => (int) (clone $callLedger)->count(),
                    'audio_calls' => (int) (clone $callLedger)->where('call_sessions.type', 'audio')->count(),
                    'video_calls' => (int) (clone $callLedger)->where('call_sessions.type', 'video')->count(),
                    'minutes' => (int) (clone $callLedger)->sum('call_earning_ledgers.billable_minutes'),
                    'call_coins' => (int) (clone $callLedger)->sum('call_earning_ledgers.total_coins'),
                    'host_earnings' => (int) (clone $callLedger)->sum('call_earning_ledgers.host_earning'),
                    'agency_earnings' => (int) (clone $callLedger)->sum('call_earning_ledgers.agency_earning'),
                    'live_rooms' => $roomMetrics['count'],
                    'live_minutes' => $roomMetrics['minutes'],
                    'live_gift_coins' => (int) (clone $liveGifts)->sum('live_room_gift_earning_ledgers.total_coins'),
                    'live_host_earnings' => (int) (clone $liveGifts)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                    'live_agency_earnings' => (int) (clone $liveGifts)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                    'pk_gift_coins' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.total_coins'),
                    'pk_host_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                    'pk_agency_earnings' => (int) (clone $pkGifts)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                    'pk_event_count' => (int) (clone $pkGifts)->count(),
                ];
            })
            ->filter(fn (array $row) => collect($row)->except('week_start')->sum() > 0)
            ->values();

        $liveMetrics = $this->roomMetrics($from, $to, $host->id);

        $report = [
            'host' => $host,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'followers' => (int) $host->followers()->count(),
                'calls' => (int) (clone $callLedgerBase)->count(),
                'audio_calls' => (int) (clone $callLedgerBase)->where('call_sessions.type', 'audio')->count(),
                'video_calls' => (int) (clone $callLedgerBase)->where('call_sessions.type', 'video')->count(),
                'completed_calls' => (int) (clone $callBase)->where('status', 'ended')->count(),
                'failed_calls' => (int) (clone $callBase)->whereIn('status', ['failed', 'missed', 'rejected'])->count(),
                'minutes' => (int) (clone $callLedgerBase)->sum('call_earning_ledgers.billable_minutes'),
                'call_coins' => (int) (clone $callLedgerBase)->sum('call_earning_ledgers.total_coins'),
                'host_earnings' => (int) (clone $callLedgerBase)->sum('call_earning_ledgers.host_earning'),
                'agency_earnings' => (int) (clone $callLedgerBase)->sum('call_earning_ledgers.agency_earning'),
                'live_rooms' => $liveMetrics['count'],
                'live_minutes' => $liveMetrics['minutes'],
                'live_gift_coins' => (int) (clone $giftBase)->sum('live_room_gift_earning_ledgers.total_coins'),
                'live_host_earnings' => (int) (clone $giftBase)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                'live_agency_earnings' => (int) (clone $giftBase)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                'pk_gift_coins' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.total_coins'),
                'pk_host_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.host_payout_coins'),
                'pk_agency_earnings' => (int) (clone $pkBase)->sum('live_room_gift_earning_ledgers.agency_payout_coins'),
                'pk_event_count' => (int) (clone $pkBase)->count(),
                'participants_total' => (int) LiveRoomParticipant::query()
                    ->whereIn('live_room_id', $roomIds)
                    ->count(),
                'participants_unique' => (int) $participantsBase
                    ->get(['user_id', 'session_id'])
                    ->map(fn ($row) => $row->user_id ? 'user:' . $row->user_id : 'sess:' . $row->session_id)
                    ->filter()
                    ->unique()
                    ->count(),
            ],
            'weekly_breakdown' => $weeklyBreakdown,
            'recent_calls' => (clone $callBase)->latest('id')->limit(20)->get(),
            'recent_live_rooms' => (clone $liveBase)->latest('id')->limit(20)->get(),
            'followers' => $host->followers()->with('user')->latest('id')->limit(20)->get(),
        ];

        return view('admin.reports.host-show', compact('report'));
    }

    public function levels(Request $request)
    {
        $q = User::query()->with('level');
        if ($search = $request->string('q')->toString()) {
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($request->filled('level_id')) {
            $q->where('level_id', $request->integer('level_id'));
        }

        $topSpenders = $q->orderByDesc('lifetime_spend_coins')->paginate(25)->withQueryString();
        $levels = UserLevel::query()->orderBy('sort_order')->get();
        $distribution = User::query()
            ->selectRaw('level_id, COUNT(*) as total')
            ->groupBy('level_id')
            ->pluck('total', 'level_id');
        $history = UserLevelHistory::query()
            ->with(['user', 'oldLevel', 'newLevel'])
            ->latest('id')
            ->limit(50)
            ->get();

        return view('admin.reports.levels', compact('topSpenders', 'levels', 'distribution', 'history'));
    }

    private function regularGiftLedgerBase($from, $to)
    {
        return LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->leftJoin('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->whereNull('live_room_pk_events.id')
            ->whereBetween('live_room_gift_earning_ledgers.created_at', [$from, $to]);
    }

    private function pkGiftLedgerBase($from, $to)
    {
        return LiveRoomGiftEarningLedger::query()
            ->join('live_room_gifts', 'live_room_gifts.id', '=', 'live_room_gift_earning_ledgers.live_room_gift_id')
            ->join('live_room_pk_events', function ($join) {
                $join->on('live_room_pk_events.wallet_transaction_id', '=', 'live_room_gifts.transaction_id')
                    ->where('live_room_pk_events.event_type', '=', 'gift');
            })
            ->whereBetween('live_room_gift_earning_ledgers.created_at', [$from, $to]);
    }

    private function roomOverlapBase($from, $to, int $hostId)
    {
        return LiveRoom::query()
            ->where('host_id', $hostId)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $to)
            ->whereRaw('COALESCE(ended_at, last_activity_at, started_at) >= ?', [$from->toDateTimeString()]);
    }

    private function roomMetrics($from, $to, int $hostId): array
    {
        $rooms = $this->roomOverlapBase($from, $to, $hostId)
            ->get(['started_at', 'ended_at', 'last_activity_at']);

        $totalMinutes = 0;
        foreach ($rooms as $room) {
            $roomStart = $room->started_at?->copy();
            $roomEnd = ($room->ended_at ?? $room->last_activity_at ?? $room->started_at)?->copy();
            if (!$roomStart || !$roomEnd) {
                continue;
            }

            $effectiveStart = $roomStart->greaterThan($from) ? $roomStart : $from->copy();
            $effectiveEnd = $roomEnd->lessThan($to) ? $roomEnd : $to->copy();
            if ($effectiveEnd->lessThanOrEqualTo($effectiveStart)) {
                continue;
            }

            $minutes = (int) floor($effectiveStart->diffInSeconds($effectiveEnd) / 60);
            if ($minutes <= 0) {
                continue;
            }

            $totalMinutes += $minutes;
        }

        return [
            'count' => (int) $rooms->count(),
            'minutes' => $totalMinutes,
        ];
    }

    private function successfulCallLedgerBase($from, $to)
    {
        return CallEarningLedger::query()
            ->join('call_sessions', 'call_sessions.id', '=', 'call_earning_ledgers.call_session_id')
            ->where('call_sessions.status', 'ended')
            ->where('call_earning_ledgers.total_coins', '>', 0)
            ->whereBetween('call_earning_ledgers.created_at', [$from, $to]);
    }
}
