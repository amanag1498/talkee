<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $from = $request->date('from') ?: now()->subDays(6)->startOfDay();
        $to = $request->date('to') ?: now()->endOfDay();

        $hosts = Host::with(['user', 'agency'])->orderBy('id', 'desc')->limit(500)->get();
        $hostsById = $hosts->keyBy('id');

        $rooms = LiveRoom::query()
            ->when($hostId, fn ($query) => $query->where('host_id', $hostId))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('started_at', [$from, $to])
                    ->orWhereBetween('ended_at', [$from, $to]);
            })
            ->get(['id', 'host_id', 'started_at', 'ended_at']);

        $roomIds = $rooms->pluck('id');
        $reportHostIds = $hostId
            ? collect([$hostId])
            : $hosts->pluck('id')->unique()->values();

        $giftAgg = LiveRoomGiftEarningLedger::query()
            ->when($roomIds->isNotEmpty(), fn ($query) => $query->whereIn('live_room_id', $roomIds))
            ->selectRaw('host_id, DATE(created_at) as d, SUM(total_coins) as gift_coins, COUNT(*) as gift_events')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

        $callAgg = CallSession::query()
            ->when($hostId, fn ($query) => $query->where('host_id', $hostId))
            ->whereNotNull('host_id')
            ->where('status', 'ended')
            ->whereBetween('ended_at', [$from, $to])
            ->selectRaw('host_id, DATE(ended_at) as d, SUM(total_coins_charged) as call_coins, COUNT(*) as call_count')
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

        $durAgg = LiveRoom::query()
            ->when($roomIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $roomIds))
            ->selectRaw('host_id,
                         DATE(COALESCE(started_at, created_at)) as d,
                         SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW()))) as duration_sec,
                         COUNT(*) as rooms')
            ->groupBy('host_id', 'd')
            ->get()
            ->groupBy('d');

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
                $call = optional($callAgg->get($key))->firstWhere('host_id', $hid);
                $participants = optional($partAgg->get($key))->firstWhere('host_id', $hid);
                $duration = optional($durAgg->get($key))->firstWhere('host_id', $hid);

                $callCoins = (int) ($call->call_coins ?? 0);
                $giftCoins = (int) ($gift->gift_coins ?? 0);
                $grossCoins = $callCoins + $giftCoins;
                $hostPct = (float) ($host->payout_percentage ?? 0);
                $agencyPct = (float) ($host->agency?->payout_percentage ?? 0);

                $days->push([
                    'date' => $key,
                    'host_id' => $hid,
                    'rooms' => (int) ($duration->rooms ?? 0),
                    'duration_min' => (int) round(((int) ($duration->duration_sec ?? 0)) / 60),
                    'participants_total' => (int) ($participants->participants_total ?? 0),
                    'participants_unique' => (int) ($participants->participants_unique ?? 0),
                    'call_coins' => $callCoins,
                    'call_count' => (int) ($call->call_count ?? 0),
                    'gift_coins' => $giftCoins,
                    'gift_events' => (int) ($gift->gift_events ?? 0),
                    'gross_coins' => $grossCoins,
                    'host_payout_percentage' => $hostPct,
                    'agency_payout_percentage' => $agencyPct,
                    'host_weekly_bonus' => 0,
                    'host_payable' => (int) floor(($grossCoins * $hostPct) / 100),
                    'agency_payable' => (int) floor(($grossCoins * $agencyPct) / 100),
                ]);
            }
        }

        $rows = $days;

        if ($range === 'weekly') {
            $rows = $rows
                ->groupBy(fn ($row) => Carbon::parse($row['date'])->startOfWeek(Carbon::MONDAY)->format('Y-m-d'))
                ->flatMap(function ($weekRows, $weekStart) use ($hostsById) {
                    return $weekRows->groupBy('host_id')->map(function ($group) use ($weekStart, $hostsById) {
                        $host = $hostsById->get($group->first()['host_id']);
                        $grossCoins = (int) $group->sum('gross_coins');
                        $hostPct = (float) ($host?->payout_percentage ?? 0);
                        $agencyPct = (float) ($host?->agency?->payout_percentage ?? 0);
                        $hostWeeklyBonus = (int) ($host?->weekly_bonus ?? 0);

                        return [
                            'week_start' => $weekStart,
                            'host_id' => $group->first()['host_id'],
                            'rooms' => (int) $group->sum('rooms'),
                            'duration_min' => (int) $group->sum('duration_min'),
                            'participants_total' => (int) $group->sum('participants_total'),
                            'participants_unique' => (int) $group->sum('participants_unique'),
                            'call_coins' => (int) $group->sum('call_coins'),
                            'call_count' => (int) $group->sum('call_count'),
                            'gift_coins' => (int) $group->sum('gift_coins'),
                            'gift_events' => (int) $group->sum('gift_events'),
                            'gross_coins' => $grossCoins,
                            'host_payout_percentage' => $hostPct,
                            'agency_payout_percentage' => $agencyPct,
                            'host_weekly_bonus' => $hostWeeklyBonus,
                            'host_payable' => (int) floor(($grossCoins * $hostPct) / 100) + $hostWeeklyBonus,
                            'agency_payable' => (int) floor(($grossCoins * $agencyPct) / 100),
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
                ? ['week_start', 'host_id', 'rooms', 'duration_min', 'participants_total', 'participants_unique', 'call_coins', 'call_count', 'gift_coins', 'gift_events', 'gross_coins', 'host_payout_percentage', 'host_weekly_bonus', 'host_payable', 'agency_payout_percentage', 'agency_payable']
                : ['date', 'host_id', 'rooms', 'duration_min', 'participants_total', 'participants_unique', 'call_coins', 'call_count', 'gift_coins', 'gift_events', 'gross_coins', 'host_payout_percentage', 'host_payable', 'agency_payout_percentage', 'agency_payable']
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
        $from = $request->date('from') ?: now()->subDays(6)->startOfDay();
        $to = $request->date('to') ?: now()->endOfDay();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $host->load(['user', 'agency', 'followers.user']);

        $callBase = CallSession::query()
            ->with(['caller', 'receiver', 'agency'])
            ->where('host_id', $host->id)
            ->whereBetween('created_at', [$from, $to]);

        $liveBase = LiveRoom::query()
            ->where('host_id', $host->id)
            ->whereBetween('created_at', [$from, $to]);

        $giftBase = LiveRoomGiftEarningLedger::query()
            ->where('host_id', $host->id)
            ->whereBetween('created_at', [$from, $to]);

        $roomIds = (clone $liveBase)->pluck('id');
        $participantsBase = LiveRoomParticipant::query()->whereIn('live_room_id', $roomIds);

        $weeklyBreakdown = collect(CarbonPeriod::create($from->copy()->startOfWeek(Carbon::MONDAY), '1 week', $to))
            ->map(function ($weekStart) use ($host, $to) {
                $weekFrom = $weekStart->copy()->startOfDay();
                $weekTo = $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()->min($to);

                $calls = CallSession::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$weekFrom, $weekTo]);
                $liveRooms = LiveRoom::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$weekFrom, $weekTo]);
                $liveGifts = LiveRoomGiftEarningLedger::query()
                    ->where('host_id', $host->id)
                    ->whereBetween('created_at', [$weekFrom, $weekTo]);

                return [
                    'week_start' => $weekFrom->format('Y-m-d'),
                    'calls' => (int) (clone $calls)->count(),
                    'audio_calls' => (int) (clone $calls)->where('type', 'audio')->count(),
                    'video_calls' => (int) (clone $calls)->where('type', 'video')->count(),
                    'minutes' => (int) (clone $calls)->sum('billable_minutes'),
                    'call_coins' => (int) (clone $calls)->sum('total_coins_charged'),
                    'host_earnings' => (int) (clone $calls)->sum('host_earning'),
                    'agency_earnings' => (int) (clone $calls)->sum('agency_earning'),
                    'live_rooms' => (int) (clone $liveRooms)->count(),
                    'live_minutes' => (int) (clone $liveRooms)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                    'live_gift_coins' => (int) (clone $liveGifts)->sum('total_coins'),
                    'live_host_earnings' => (int) (clone $liveGifts)->sum('host_payout_coins'),
                    'live_agency_earnings' => (int) (clone $liveGifts)->sum('agency_payout_coins'),
                ];
            })
            ->filter(fn (array $row) => collect($row)->except('week_start')->sum() > 0)
            ->values();

        $report = [
            'host' => $host,
            'from' => $from,
            'to' => $to,
            'summary' => [
                'followers' => (int) $host->followers()->count(),
                'calls' => (int) (clone $callBase)->count(),
                'audio_calls' => (int) (clone $callBase)->where('type', 'audio')->count(),
                'video_calls' => (int) (clone $callBase)->where('type', 'video')->count(),
                'completed_calls' => (int) (clone $callBase)->where('status', 'ended')->count(),
                'failed_calls' => (int) (clone $callBase)->whereIn('status', ['failed', 'missed', 'rejected'])->count(),
                'minutes' => (int) (clone $callBase)->sum('billable_minutes'),
                'call_coins' => (int) (clone $callBase)->sum('total_coins_charged'),
                'host_earnings' => (int) (clone $callBase)->sum('host_earning'),
                'agency_earnings' => (int) (clone $callBase)->sum('agency_earning'),
                'live_rooms' => (int) (clone $liveBase)->count(),
                'live_minutes' => (int) (clone $liveBase)->get()->sum(fn (LiveRoom $room) => (int) ($room->duration_minutes ?? 0)),
                'live_gift_coins' => (int) (clone $giftBase)->sum('total_coins'),
                'live_host_earnings' => (int) (clone $giftBase)->sum('host_payout_coins'),
                'live_agency_earnings' => (int) (clone $giftBase)->sum('agency_payout_coins'),
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
}
