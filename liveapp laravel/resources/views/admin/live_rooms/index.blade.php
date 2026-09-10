@extends('layouts.admin-berry')
@section('title','Live Rooms')

@php
  $roomCollection = $rooms->getCollection();
  $liveCount = $roomCollection->where('status', 'live')->count();
  $openSpeakers = (int) $roomCollection->sum(fn ($room) => (int) ($room->open_host_count ?? 0) + (int) ($room->open_speaker_count ?? 0));
  $pendingRequests = (int) $roomCollection->sum('pending_request_count');
@endphp

@section('content')
<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card"><div class="card-body"><div class="text-muted small">Visible Rooms</div><div class="fs-3 fw-semibold">{{ $rooms->total() }}</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body"><div class="text-muted small">Live Now</div><div class="fs-3 fw-semibold text-success">{{ $liveCount }}</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body"><div class="text-muted small">Current Speakers</div><div class="fs-3 fw-semibold">{{ $openSpeakers }}</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body"><div class="text-muted small">Pending Camera Requests</div><div class="fs-3 fw-semibold text-warning">{{ $pendingRequests }}</div></div></div>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <h5 class="mb-0"><i class="ti ti-video me-2"></i>Live Rooms</h5>
    <div class="d-flex gap-2 flex-wrap">
      <form method="get" class="d-flex gap-2 flex-wrap">
        <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search room_id, host, title">
        <select name="status" class="form-select">
          <option value="">Any status</option>
          @foreach(['scheduled','live','ended'] as $st)
            <option value="{{ $st }}" @selected(request('status')===$st)>{{ ucfirst($st) }}</option>
          @endforeach
        </select>
        <input type="date" class="form-control" name="from" value="{{ request('from') }}">
        <input type="date" class="form-control" name="to" value="{{ request('to') }}">
        <button class="btn btn-light border">Filter</button>
      </form>
      <a class="btn btn-primary" href="{{ route('admin.live-rooms.create') }}"><i class="ti ti-plus me-1"></i>New Room</a>
    </div>
  </div>

  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Room</th>
          <th>Host</th>
          <th>Status</th>
          <th>Open</th>
          <th>On Camera</th>
          <th>Pending Requests</th>
          <th>Max Speakers</th>
          <th>Peak Viewers</th>
          <th>Started</th>
          <th>Ended</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($rooms as $r)
        <tr>
          <td>{{ $r->id }}</td>
          <td>
            <div class="fw-semibold">{{ $r->room_id }}</div>
            <div class="small text-muted">{{ $r->title ?? '—' }}</div>
            <div class="small text-muted">{{ $r->end_reason ? ucfirst(str_replace('_',' ',$r->end_reason)) : '—' }}</div>
          </td>
          <td>
            <div>
              @if($r->host?->user)
                <a href="{{ route('admin.users.show', $r->host->user) }}">{{ $r->host->user->name }}</a>
              @else
                —
              @endif
            </div>
            <div class="small text-muted">User #{{ $r->host?->user?->id ?? '—' }}</div>
          </td>
          <td>
            <span class="badge {{ $r->status==='live' ? 'bg-success' : ($r->status==='ended' ? 'bg-secondary' : 'bg-warning text-dark') }}">
              {{ ucfirst($r->status) }}
            </span>
            <div class="small text-muted mt-1">Host {{ ($r->open_host_count ?? 0) > 0 ? 'active' : 'offline' }}</div>
          </td>
          <td>{{ $r->open_participant_count ?? 0 }}</td>
          <td>{{ (int) ($r->open_host_count ?? 0) + (int) ($r->open_speaker_count ?? 0) }}</td>
          <td>{{ $r->pending_request_count ?? 0 }}</td>
          <td>{{ $r->max_speakers ?? config('live_rooms.' . ($r->room_type ?? 'video') . '.max_speakers', 4) }}</td>
          <td>{{ number_format($r->peak_viewers) }}</td>
          <td>{{ $r->started_at?->format('d M Y H:i') ?? '—' }}</td>
          <td>{{ $r->ended_at?->format('d M Y H:i') ?? '—' }}</td>
          <td class="text-end">
            <a class="btn btn-sm btn-light border" href="{{ route('admin.live-rooms.show',$r) }}">View</a>
            @if($r->status === 'live' && !$r->ended_at)
              <a class="btn btn-sm btn-light border" href="{{ route('admin.live-rooms.watch',$r) }}" target="_blank" rel="noopener">Watch</a>
            @endif
            <a class="btn btn-sm btn-primary" href="{{ route('admin.live-rooms.edit',$r) }}">Edit</a>
            @if($r->status!=='ended')
              <form method="post" action="{{ route('admin.live-rooms.end',$r) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-danger" onclick="return confirm('Force end this room?')">Force End</button>
              </form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="12" class="text-center text-muted py-5">No rooms match the current filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-end">
    {{ $rooms->withQueryString()->links() }}
  </div>
</div>
@endsection
