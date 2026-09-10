@extends('layouts.admin-berry')
@section('title','Live Room #'.$live_room->id)

@section('content')
<div class="row g-3 mb-3">
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">Participants</div><div class="fs-3 fw-semibold">{{ $stats['participants_open'] }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">On Camera</div><div class="fs-3 fw-semibold">{{ $stats['active_speaker_count'] }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">Pending Requests</div><div class="fs-3 fw-semibold">{{ $seatSnapshot['pending_count'] }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">Peak Viewers</div><div class="fs-3 fw-semibold">{{ number_format($live_room->peak_viewers) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">Duration</div><div class="fs-3 fw-semibold">{{ $stats['duration_min'] ? $stats['duration_min'].'m' : '—' }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="small text-muted">Gift Coins</div><div class="fs-3 fw-semibold">{{ number_format($stats['gift_coins']) }}</div></div></div></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Gift Host Earnings</div><div class="fs-3 fw-semibold">{{ number_format($stats['gift_host_earnings']) }}</div></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Gift Agency Earnings</div><div class="fs-3 fw-semibold">{{ number_format($stats['gift_agency_earnings']) }}</div></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Gift Platform Earnings</div><div class="fs-3 fw-semibold">{{ number_format($stats['gift_platform_earnings']) }}</div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Room Overview</h6>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-primary" href="{{ route('admin.live-rooms.edit',$live_room) }}">Edit</a>
          @if($live_room->status === 'live' && !$live_room->ended_at)
            <a class="btn btn-sm btn-light border" href="{{ route('admin.live-rooms.watch',$live_room) }}" target="_blank" rel="noopener">Watch silently</a>
          @endif
          @if($live_room->status !== 'ended')
            <form method="post" action="{{ route('admin.live-rooms.end',$live_room) }}">@csrf
              <button class="btn btn-sm btn-danger" onclick="return confirm('Force end this room?')">Force End</button>
            </form>
          @endif
        </div>
      </div>
      <div class="card-body vstack gap-2">
        <div><span class="text-muted">Room ID:</span> <code>{{ $live_room->room_id }}</code></div>
        <div><span class="text-muted">Title:</span> {{ $live_room->title ?? '—' }}</div>
        <div>
          <span class="text-muted">Host:</span>
          @if($live_room->host?->user)
            <a href="{{ route('admin.users.show', $live_room->host->user) }}">{{ $live_room->host->user->name }}</a>
          @else
            —
          @endif
          <span class="text-muted">(User #{{ $live_room->host?->user?->id ?? '—' }})</span>
        </div>
        <div><span class="text-muted">Status:</span> <span class="badge {{ $live_room->status==='live' ? 'bg-success' : ($live_room->status==='ended' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ ucfirst($live_room->status) }}</span></div>
        <div><span class="text-muted">Started:</span> {{ $live_room->started_at?->format('d M Y H:i') ?? '—' }}</div>
        <div><span class="text-muted">Ended:</span> {{ $live_room->ended_at?->format('d M Y H:i') ?? '—' }}</div>
        <div><span class="text-muted">End Reason:</span> {{ $live_room->end_reason ?? '—' }}</div>
        <div><span class="text-muted">Current Speakers:</span> {{ $stats['active_speaker_count'] }} / {{ $live_room->max_speakers ?? config('live_rooms.' . ($live_room->room_type ?? 'video') . '.max_speakers', 4) }}</div>
        <div><span class="text-muted">Pending Camera Requests:</span> {{ $seatSnapshot['pending_count'] }}</div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><h6 class="mb-0">Consistency Checks</h6></div>
      <div class="card-body">
        <ul class="mb-0">
          <li>No active host: {{ $consistency['live_room_with_no_active_host'] ? 'Yes' : 'No' }}</li>
          <li>Ended with open participants: {{ $consistency['ended_room_with_open_participants'] ? 'Yes' : 'No' }}</li>
          <li>Pending request without participant: {{ count($consistency['pending_request_for_user_not_in_room']) }}</li>
          <li>Speaker without accepted request: {{ count($consistency['speaker_role_without_accepted_request']) }}</li>
          <li>Redis missing live room: {{ $consistency['redis_missing_live_room'] ? 'Yes' : 'No' }}</li>
          <li>Redis has ended room: {{ $consistency['redis_has_ended_room'] ? 'Yes' : 'No' }}</li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Current Speakers</h6>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr><th>User</th><th>Joined</th><th>Speaker Since</th><th>Role</th><th class="text-end">Action</th></tr>
          </thead>
          <tbody>
          @forelse($seatSnapshot['speakers'] as $speaker)
            <tr>
              <td>
                @if(!empty($speaker['user_id']))
                  <a href="{{ route('admin.users.show', $speaker['user_id']) }}">{{ $speaker['name'] ?? '—' }}</a>
                @else
                  {{ $speaker['name'] ?? '—' }}
                @endif
                <div class="small text-muted">User #{{ $speaker['user_id'] }}</div>
              </td>
              <td>{{ $speaker['joined_at'] ? \Carbon\Carbon::parse($speaker['joined_at'])->format('d M Y H:i') : '—' }}</td>
              <td>{{ $speaker['speaker_since'] ? \Carbon\Carbon::parse($speaker['speaker_since'])->format('d M Y H:i') : '—' }}</td>
              <td>{{ ucfirst($speaker['role']) }}</td>
              <td class="text-end">
                <form method="post" action="{{ route('admin.live-rooms.speakers.remove', [$live_room, $speaker['user_id']]) }}">@csrf
                  <input type="hidden" name="reason" value="admin_removed_speaker">
                  <button class="btn btn-sm btn-danger" onclick="return confirm('Remove this speaker from camera?')">Remove</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No active speakers.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">Seat Request History</h6>
        <div class="d-flex gap-2">
          <form method="get" class="d-flex gap-2">
            <select name="request_status" class="form-select">
              <option value="">All statuses</option>
              @foreach(['pending','accepted','rejected','cancelled','removed','expired'] as $status)
                <option value="{{ $status }}" @selected(request('request_status') === $status)>{{ ucfirst($status) }}</option>
              @endforeach
            </select>
            <button class="btn btn-light border">Filter</button>
          </form>
          <a class="btn btn-light border" href="{{ route('admin.live-rooms.requests.export', $live_room) }}">Export CSV</a>
        </div>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Request</th>
              <th>User</th>
              <th>Status</th>
              <th>Requested</th>
              <th>Responded</th>
              <th>Removed</th>
              <th>Reason</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
          @forelse($seatSnapshot['requests'] as $row)
            <tr>
              <td>#{{ $row['request_id'] }}</td>
              <td>
                @if(!empty($row['user_id']))
                  <a href="{{ route('admin.users.show', $row['user_id']) }}">{{ data_get($row, 'user.name', '—') }}</a>
                @else
                  {{ data_get($row, 'user.name', '—') }}
                @endif
                <div class="small text-muted">User #{{ $row['user_id'] }}</div>
              </td>
              <td><span class="badge bg-light text-dark border">{{ ucfirst($row['status']) }}</span><div class="small text-muted">Role: {{ ucfirst($row['role']) }}</div></td>
              <td>{{ $row['requested_at'] ? \Carbon\Carbon::parse($row['requested_at'])->format('d M Y H:i') : '—' }}</td>
              <td>{{ $row['responded_at'] ? \Carbon\Carbon::parse($row['responded_at'])->format('d M Y H:i') : '—' }}</td>
              <td>{{ $row['removed_at'] ? \Carbon\Carbon::parse($row['removed_at'])->format('d M Y H:i') : '—' }}</td>
              <td>{{ $row['remove_reason'] ?? '—' }}</td>
              <td class="text-end">
                @if($row['status'] === 'pending')
                  <form method="post" action="{{ route('admin.live-rooms.seat-requests.reject', [$live_room, $row['request_id']]) }}" class="d-inline">@csrf
                    <input type="hidden" name="reason" value="admin_force_reject">
                    <button class="btn btn-sm btn-warning" onclick="return confirm('Reject this pending request?')">Reject</button>
                  </form>
                @else
                  <span class="text-muted small">No action</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="8" class="text-center text-muted py-5">No seat requests for this room.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header"><h6 class="mb-0">Audit Log</h6></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Before</th><th>After</th><th>Reason</th></tr>
          </thead>
          <tbody>
          @forelse($live_room->adminAudits->sortByDesc('id') as $audit)
            <tr>
              <td>{{ $audit->created_at?->format('d M Y H:i') }}</td>
              <td>{{ $audit->admin?->name ?? '—' }}</td>
              <td>{{ $audit->action }}</td>
              <td>{{ $audit->targetUser?->name ?? ($audit->target_user_id ? 'User #'.$audit->target_user_id : '—') }}</td>
              <td>{{ $audit->before_status ?? '—' }}</td>
              <td>{{ $audit->after_status ?? '—' }}</td>
              <td>{{ $audit->reason ?? '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No admin actions recorded for this room.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection
