<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Models\LiveRoom;
use App\Models\LiveRoomSeatRequest;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\LiveRoomBroadcaster;
use App\Services\LiveRoomSeatService;
use App\Services\LiveRoomStateService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveRoomAdminController extends Controller
{
    public function __construct(
        private LiveRoomSeatService $seats,
        private LiveRoomStateService $state,
    ) {
    }

    private function configuredMaxSpeakers(string $roomType = 'video'): int
    {
        $fallback = $roomType === 'audio' ? 8 : 4;

        return max(1, (int) config("live_rooms.{$roomType}.max_speakers", $fallback));
    }

    private function configuredMaxParticipants(string $roomType = 'video'): int
    {
        $fallback = $roomType === 'audio' ? 50 : 12;

        return max(2, (int) config("live_rooms.{$roomType}.max_participants", $fallback));
    }

    public function index(Request $request)
    {
        $q = LiveRoom::with(['host.user'])
            ->withCount([
                'participants as open_participant_count' => fn ($query) => $query->whereNull('left_at'),
                'participants as open_host_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'host'),
                'participants as open_speaker_count' => fn ($query) => $query->whereNull('left_at')->where('role', 'speaker'),
                'seatRequests as pending_request_count' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->latest();

        if ($s = $request->string('s')->trim()) {
            $q->where('room_id','like',"%{$s}%")
              ->orWhere('title','like',"%{$s}%")
              ->orWhereHas('host.user', fn($u)=>$u->where('name','like',"%{$s}%")->orWhere('email','like',"%{$s}%"));
        }
        if ($st = $request->string('status')->trim()) {
            $q->where('status', $st);
        }
        if ($from = $request->date('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $rooms = $q->paginate(20);
        return view('admin.live_rooms.index', compact('rooms'));
    }

    public function create()
    {
        $hosts = Host::with('user')->orderBy('id','desc')->limit(200)->get();
        $roomSettings = config('live_rooms');
        return view('admin.live_rooms.create', compact('hosts', 'roomSettings'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'host_id'      => 'required|exists:hosts,id',
            'room_id'      => 'required|string|max:100|unique:live_rooms,room_id',
            'title'        => 'nullable|string|max:150',
            'status'       => 'required|in:scheduled,live,ended',
            'scheduled_at' => 'nullable|date',
            'started_at'   => 'nullable|date',
            'ended_at'     => 'nullable|date|after_or_equal:started_at',
            'end_reason'   => 'nullable|string|max:50',
            'peak_viewers' => 'nullable|integer|min:0',
            'max_speakers' => 'nullable|integer|min:1|max:' . $this->configuredMaxSpeakers('video'),
        ]);

        $room = LiveRoom::create($data + ['meta' => null, 'last_activity_at' => now()]);
        LiveRoomBroadcaster::broadcast(
        $room->fresh(),
        ($room->status === 'live' ? 'live' : 'created')
    );

        return redirect()->route('admin.live-rooms.index')->with('ok','Live room created.');
    }

    

    public function edit(LiveRoom $live_room)
    {
        $hosts = Host::with('user')->orderBy('id','desc')->limit(200)->get();
        $roomSettings = config('live_rooms');
        return view('admin.live_rooms.edit', compact('live_room','hosts', 'roomSettings'));
    }

    public function update(Request $request, LiveRoom $live_room)
    {
        $data = $request->validate([
            'host_id'      => 'required|exists:hosts,id',
            'room_id'      => 'required|string|max:100|unique:live_rooms,room_id,'.$live_room->id,
            'title'        => 'nullable|string|max:150',
            'status'       => 'required|in:scheduled,live,ended',
            'scheduled_at' => 'nullable|date',
            'started_at'   => 'nullable|date',
            'ended_at'     => 'nullable|date|after_or_equal:started_at',
            'end_reason'   => 'nullable|string|max:50',
            'peak_viewers' => 'nullable|integer|min:0',
            'max_speakers' => 'nullable|integer|min:1|max:' . $this->configuredMaxSpeakers((string) ($live_room->room_type ?? 'video')),
        ]);


            $beforeStatus = $live_room->status;
    $beforeTitle  = (string) $live_room->title;
    $beforeHost   = (int) $live_room->host_id;

    $live_room->update($data + ['last_activity_at' => now()]);
    $room = $live_room->fresh();

    // 🔊 decide & broadcast
    if ($room->status === 'ended') {
        LiveRoomBroadcaster::broadcast($room, 'ended');
    } elseif ($room->status === 'live' && $beforeStatus !== 'live') {
        LiveRoomBroadcaster::broadcast($room, 'live');   // transitioned to live
    } else {
        $changed = $beforeTitle !== (string) $room->title || $beforeHost !== (int) $room->host_id;
        LiveRoomBroadcaster::broadcast($room, $changed ? 'updated' : 'updated'); // safe default
    }

        return redirect()->route('admin.live-rooms.index')->with('ok','Live room updated.');
    }

    public function endRoom(LiveRoom $live_room)
    {
        if ($live_room->status !== 'ended') {
            $this->seats->endRoom($live_room, 'admin_force_end', request()->user());
        }
        return back()->with('ok','Room marked as ended.');
    }
    public function show(LiveRoom $live_room)
{
    $requestStatus = request()->string('request_status')->trim()->toString();
    $live_room->load(['host.user','gifts.sender','gifts.gift','giftEarningLedgers','participants.user','adminAudits.admin','adminAudits.targetUser']);

    $stats = [
        'participants_total'        => $live_room->participants->count(),
        'participants_open'         => $live_room->participants->whereNull('left_at')->count(),
        'host_open'                 => $live_room->participants->whereNull('left_at')->where('role', 'host')->count(),
        'speaker_open'              => $live_room->participants->whereNull('left_at')->where('role', 'speaker')->count(),
        'active_speaker_count'      => $live_room->participants->whereNull('left_at')->whereIn('role', ['host', 'speaker'])->count(),
        'pending_requests'          => $live_room->seatRequests()->where('status', 'pending')->count(),
        'participants_unique_users' => $live_room->participants->pluck('user_id')->filter()->unique()->count(),
        'participants_unique'       => $live_room->participants->map(fn($p)=>$p->user_id ?: ('sess:'.$p->session_id))->unique()->count(),
        'gift_coins'                => (int) $live_room->gifts->sum('total_coins'),
        'gift_events'               => (int) $live_room->gifts->sum('quantity'),
        'gift_host_earnings'        => (int) $live_room->giftEarningLedgers->sum('host_payout_coins'),
        'gift_agency_earnings'      => (int) $live_room->giftEarningLedgers->sum('agency_payout_coins'),
        'gift_platform_earnings'    => (int) $live_room->giftEarningLedgers->sum('platform_revenue_coins'),
        'duration_min'              => $live_room->duration_minutes,
    ];

    $seatSnapshot = $this->seats->snapshot($live_room, request()->user(), ['status' => $requestStatus]);
    $consistency = $this->seats->roomConsistency($live_room);

    return view('admin.live_rooms.show', compact('live_room','stats', 'seatSnapshot', 'consistency'));
}

    public function rejectSeatRequest(Request $request, LiveRoom $live_room, LiveRoomSeatRequest $seat_request)
    {
        $this->seats->rejectRequest($live_room, $seat_request, $request->user(), $request->input('reason', 'admin_force_reject'));

        return back()->with('ok', 'Seat request rejected.');
    }

    public function removeSpeaker(Request $request, LiveRoom $live_room, User $user)
    {
        $this->seats->removeSpeaker($live_room, $user, $request->user(), $request->input('reason', 'admin_removed_speaker'));

        return back()->with('ok', 'Speaker removed.');
    }

    public function exportRequests(LiveRoom $live_room): StreamedResponse
    {
        $snapshot = $this->seats->snapshot($live_room, request()->user(), [
            'status' => request()->string('request_status')->trim()->toString(),
        ]);

        $filename = "live-room-{$live_room->room_id}-seat-requests.csv";

        return response()->stream(function () use ($snapshot) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['request_id', 'user_id', 'user_name', 'status', 'requested_at', 'responded_at', 'responded_by', 'removed_at', 'remove_reason', 'role', 'updated_at']);
            foreach ($snapshot['requests'] as $row) {
                fputcsv($out, [
                    $row['request_id'],
                    $row['user_id'],
                    data_get($row, 'user.name'),
                    $row['status'],
                    $row['requested_at'],
                    $row['responded_at'],
                    data_get($row, 'responded_by_user.name'),
                    $row['removed_at'],
                    $row['remove_reason'],
                    $row['role'],
                    $row['updated_at'],
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }


}
