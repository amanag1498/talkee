<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomPkBattle;
use App\Services\NotifyUser;
use App\Services\AgencyDashboardService;
use App\Services\AgencyWalletService;
use App\Services\CallReportService;
use Illuminate\Http\Request;

class AgencyAdminController extends Controller
{
    public function __construct(
        private AgencyDashboardService $dashboardService,
        private AgencyWalletService $agencyWalletService,
        private CallReportService $callReportService,
    )
    {
    }

    public function index(Request $request)
    {
        $q = Agency::query()->with('owner');
        if ($s = $request->get('s')) {
            $q->where(function($qq) use ($s) {
                $qq->where('name','like',"%$s%")
                   ->orWhereHas('owner', fn($u)=>$u
                        ->where('name','like',"%$s%")
                        ->orWhere('email','like',"%$s%"));
            });
        }
        $agencies = $q->latest()->paginate(20);
        return view('admin.agencies.index', compact('agencies'));
    }

    public function edit(Agency $agency)
    {
        $agency->load('owner');
        return view('admin.agencies.edit', compact('agency'));
    }

    public function dashboard(Agency $agency)
    {
        $agency->load('owner');
        $dashboard = $this->dashboardService->build($agency);
        $walletSummary = $this->agencyWalletService->summary($agency);
        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.dashboard', compact(
            'agency',
            'dashboard',
            'walletSummary',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    public function hosts(Agency $agency)
    {
        $agency->load('owner');
        $dashboard = $this->dashboardService->build($agency, 25);
        $hosts = $dashboard['hosts'];
        $summary = $dashboard['summary'];
        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.hosts.index', compact(
            'agency',
            'hosts',
            'summary',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'profileRoute',
            'payoutReportsRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    public function hostShow(Request $request, Agency $agency, Host $host)
    {
        abort_unless((int) $host->agency_id === (int) $agency->id, 404);

        $host->load(['user', 'user.hostAvailability']);
        $detail = $this->dashboardService->hostDetail($agency, $host, $request->only(['period', 'from', 'to']));
        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $baseCallsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);
        $callsRoute = route('admin.agencies.calls.index', ['agency' => $agency->id, 'host_id' => $host->id]);

        return view('agency.hosts.show', compact(
            'agency',
            'host',
            'detail',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'profileRoute',
            'payoutReportsRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    public function calls(Request $request, Agency $agency)
    {
        $report = $this->callReportService->forAgency($request, $agency);
        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $baseCallsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.calls.index', [
            'agency' => $agency,
            'report' => $report,
            'scopeLabel' => 'Calls to Agency Hosts',
            'exportRoute' => route('admin.agencies.calls.export', array_merge(['agency' => $agency->id], $request->query())),
            'tabs' => [
                'all' => 'Calls to Agency Hosts',
                'active' => 'Active Calls',
                'completed' => 'Completed Calls',
                'host_earnings' => 'Host-wise Earnings',
            ],
            'overviewRoute' => $overviewRoute,
            'hostsIndexRoute' => $hostsIndexRoute,
            'callsRoute' => route('admin.agencies.calls.index', $agency),
            'profileRoute' => $profileRoute,
            'payoutReportsRoute' => $payoutReportsRoute,
            'videoRoomsRoute' => $videoRoomsRoute,
            'audioRoomsRoute' => $audioRoomsRoute,
            'pkBattlesRoute' => $pkBattlesRoute,
        ]);
    }

    public function exportCalls(Request $request, Agency $agency)
    {
        abort_unless($this->callReportService->schemaReady(), 409, 'Call reporting tables are not available yet. Run php artisan migrate first.');

        $rows = $this->callReportService->baseQuery($request)
            ->with(['caller', 'receiver', 'host.user', 'agency'])
            ->where('agency_id', $agency->id)
            ->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'caller', 'receiver', 'host', 'agency', 'type', 'status', 'end_reason', 'duration_seconds', 'billable_minutes', 'coins_charged', 'host_earning', 'agency_earning', 'platform_earning', 'created_at']);
            foreach ($rows as $call) {
                fputcsv($out, [
                    $call->id,
                    $call->caller?->name,
                    $call->receiver?->name,
                    $call->host?->user?->name,
                    $call->agency?->name,
                    $call->type,
                    $call->status,
                    $call->end_reason,
                    $call->duration_seconds,
                    $call->billable_minutes,
                    $call->total_coins_charged,
                    $call->host_earning,
                    $call->agency_earning,
                    $call->platform_earning,
                    optional($call->created_at)?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'admin-agency-calls-' . $agency->id . '-' . now()->format('Ymd_His') . '.csv');
    }

    public function profile(Agency $agency)
    {
        $profile = $this->dashboardService->agencyProfile($agency);
        $overviewRoute = route('admin.agencies.dashboard', $agency);
        $hostsIndexRoute = route('admin.agencies.hosts.index', $agency);
        $callsRoute = route('admin.agencies.calls.index', $agency);
        $payoutReportsRoute = route('admin.agency-payout-reports.index', ['agency_id' => $agency->id]);
        $profileRoute = route('admin.agencies.profile.show', $agency);
        $videoRoomsRoute = route('admin.agencies.video-rooms.index', $agency);
        $audioRoomsRoute = route('admin.agencies.audio-rooms.index', $agency);
        $pkBattlesRoute = route('admin.agencies.pk-battles.index', $agency);

        return view('agency.profile.show', compact(
            'agency',
            'profile',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    public function videoRooms(Request $request, Agency $agency)
    {
        return $this->roomIndex($request, $agency, 'video');
    }

    public function audioRooms(Request $request, Agency $agency)
    {
        return $this->roomIndex($request, $agency, 'audio');
    }

    public function videoRoomShow(Agency $agency, LiveRoom $live_room)
    {
        return $this->roomShow($agency, $live_room, 'video');
    }

    public function audioRoomShow(Agency $agency, LiveRoom $live_room)
    {
        return $this->roomShow($agency, $live_room, 'audio');
    }

    public function pkBattles(Request $request, Agency $agency)
    {
        $query = LiveRoomPkBattle::query()
            ->with(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom'])
            ->where(function ($inner) use ($agency) {
                $inner->whereHas('hostA', fn ($q) => $q->where('agency_id', $agency->id))
                    ->orWhereHas('hostB', fn ($q) => $q->where('agency_id', $agency->id));
            })
            ->whereHas('roomA', fn ($q) => $q->where('room_type', 'video'))
            ->whereHas('roomB', fn ($q) => $q->where('room_type', 'video'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()));

        $battles = $query->latest('id')->paginate(20)->withQueryString();
        $summary = [
            'active' => (clone $query)->where('status', 'active')->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
        ];

        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.pk-battles.index', compact(
            'agency',
            'battles',
            'summary',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    public function pkBattleShow(Agency $agency, LiveRoomPkBattle $pk_battle)
    {
        abort_unless((int) $pk_battle->hostA?->agency_id === (int) $agency->id || (int) $pk_battle->hostB?->agency_id === (int) $agency->id, 404);
        $pk_battle->load(['roomA.host.user', 'roomB.host.user', 'hostA.user', 'hostB.user', 'winnerRoom', 'events.user', 'events.gift']);

        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.pk-battles.show', compact(
            'agency',
            'pk_battle',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    private function roomIndex(Request $request, Agency $agency, string $roomType)
    {
        $rooms = LiveRoom::query()
            ->with(['host.user'])
            ->withCount([
                'participants as open_participant_count' => fn ($query) => $query->whereNull('left_at'),
                'participants as open_host_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'host'),
                'participants as open_speaker_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'speaker'),
            ])
            ->where('room_type', $roomType)
            ->whereHas('host', fn ($query) => $query->where('agency_id', $agency->id))
            ->when($request->filled('s'), function ($query) use ($request) {
                $s = $request->string('s')->trim()->toString();
                $query->where(function ($inner) use ($s) {
                    $inner->where('room_id', 'like', "%{$s}%")
                        ->orWhere('title', 'like', "%{$s}%")
                        ->orWhereHas('host.user', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('started_at')
            ->paginate(20)
            ->withQueryString();

        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.live-rooms.index', compact(
            'agency',
            'rooms',
            'roomType',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    private function roomShow(Agency $agency, LiveRoom $live_room, string $roomType)
    {
        abort_unless((int) $live_room->host?->agency_id === (int) $agency->id, 404);
        abort_unless((string) ($live_room->room_type ?? 'video') === $roomType, 404);

        $live_room->load(['host.user', 'participants.user', 'gifts.sender', 'gifts.gift', 'giftEarningLedgers']);
        $stats = [
            'participants_open' => $live_room->participants->whereNull('left_at')->count(),
            'host_open' => $live_room->participants->whereNull('left_at')->where('role', 'host')->count(),
            'speaker_open' => $live_room->participants->whereNull('left_at')->where('role', 'speaker')->count(),
            'gift_coins' => (int) $live_room->gifts->sum('total_coins'),
            'gift_events' => (int) $live_room->gifts->sum('quantity'),
            'gift_host_earnings' => (int) $live_room->giftEarningLedgers->sum('host_payout_coins'),
            'gift_agency_earnings' => (int) $live_room->giftEarningLedgers->sum('agency_payout_coins'),
            'gift_platform_earnings' => (int) $live_room->giftEarningLedgers->sum('platform_revenue_coins'),
            'duration_min' => $live_room->duration_minutes,
        ];

        $this->previewRoutes($agency, $overviewRoute, $hostsIndexRoute, $callsRoute, $payoutReportsRoute, $profileRoute, $videoRoomsRoute, $audioRoomsRoute, $pkBattlesRoute);

        return view('agency.live-rooms.show', compact(
            'agency',
            'live_room',
            'roomType',
            'stats',
            'overviewRoute',
            'hostsIndexRoute',
            'callsRoute',
            'payoutReportsRoute',
            'profileRoute',
            'videoRoomsRoute',
            'audioRoomsRoute',
            'pkBattlesRoute',
        ));
    }

    private function previewRoutes(
        Agency $agency,
        ?string &$overviewRoute,
        ?string &$hostsIndexRoute,
        ?string &$callsRoute,
        ?string &$payoutReportsRoute,
        ?string &$profileRoute,
        ?string &$videoRoomsRoute,
        ?string &$audioRoomsRoute,
        ?string &$pkBattlesRoute,
    ): void {
        $overviewRoute = route('admin.agencies.dashboard', $agency);
        $hostsIndexRoute = route('admin.agencies.hosts.index', $agency);
        $callsRoute = route('admin.agencies.calls.index', $agency);
        $payoutReportsRoute = route('admin.agency-payout-reports.index', ['agency_id' => $agency->id]);
        $profileRoute = route('admin.agencies.profile.show', $agency);
        $videoRoomsRoute = route('admin.agencies.video-rooms.index', $agency);
        $audioRoomsRoute = route('admin.agencies.audio-rooms.index', $agency);
        $pkBattlesRoute = route('admin.agencies.pk-battles.index', $agency);
    }

    public function update(Request $request, Agency $agency)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'contact_email'     => 'nullable|email|max:255',
            'contact_phone'     => 'nullable|string|max:50',
            'notes'             => 'nullable|string|max:2000',
            'payout_percentage' => 'nullable|numeric|min:0|max:100',
            'weekly_bonus'      => 'nullable|integer|min:0',
        ]);

        $agency->update([
            'name'              => $data['name'],
            'contact_email'     => $data['contact_email'] ?? $agency->contact_email,
            'contact_phone'     => $data['contact_phone'] ?? $agency->contact_phone,
            'notes'             => $data['notes'] ?? $agency->notes,
            'payout_percentage' => (float)($data['payout_percentage'] ?? $agency->payout_percentage ?? 0),
            'weekly_bonus'      => (int)  ($data['weekly_bonus']      ?? $agency->weekly_bonus      ?? 0),
        ]);

        return redirect()->route('admin.agencies.index')->with('ok','Agency updated.');
    }

    public function block(Agency $agency)
    {
        $agency->update(['is_blocked'=>true]);
         try {
        NotifyUser::send((int)$agency->owner_user_id, [
            'type'   => 'agency_blocked',
            'title'  => 'Account status changed',
            'body'   => 'Your agency account has been blocked by admin.',
            'meta'   => ['agency_id'=>$agency->id],
            'screen' => 'notifications',
        ], ['push'=>true,'persist'=>true]);
    } catch (\Throwable $e) {}

        return back()->with('ok','Agency blocked.');
    }

    public function unblock(Agency $agency)
    {
        $agency->update(['is_blocked'=>false]);
            try {
        NotifyUser::send((int)$agency->owner_user_id, [
            'type'   => 'agency_unblocked',
            'title'  => 'Account status changed',
            'body'   => 'Your agency account has been unblocked.',
            'meta'   => ['agency_id'=>$agency->id],
            'screen' => 'notifications',
        ], ['push'=>true,'persist'=>true]);
    } catch (\Throwable $e) {}

        return back()->with('ok','Agency unblocked.');
    }
}
