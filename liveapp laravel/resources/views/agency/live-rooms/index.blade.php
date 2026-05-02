@extends('layouts.agency-berry')
@section('title', ucfirst($roomType) . ' Rooms')
@section('page_intro', 'Agency-scoped ' . $roomType . ' room activity with host, status, participants, and gift visibility.')

@php
  $roomCollection = $rooms->getCollection();
  $liveCount = $roomCollection->where('status', 'live')->count();
  $openSpeakers = (int) $roomCollection->sum(fn ($room) => (int) ($room->open_host_count ?? 0) + (int) ($room->open_speaker_count ?? 0));
@endphp

@section('page_actions')
  <a class="btn btn-light border" href="{{ $overviewRoute ?? route('agency.dashboard') }}">Dashboard</a>
  <a class="btn btn-light border" href="{{ $callsRoute ?? route('agency.calls.index') }}">Call Reports</a>
  <a class="btn btn-primary" href="{{ $roomType === 'video' ? ($audioRoomsRoute ?? route('agency.audio-rooms.index')) : ($videoRoomsRoute ?? route('agency.video-rooms.index')) }}">
    {{ $roomType === 'video' ? 'Audio Rooms' : 'Video Rooms' }}
  </a>
@endsection

@section('content')
  <section class="row g-3 mb-3">
    <div class="col-md-4"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Visible Rooms</small><div class="stat-value mt-1">{{ $rooms->total() }}</div></div></div></div>
    <div class="col-md-4"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Live Now</small><div class="stat-value mt-1">{{ $liveCount }}</div></div></div></div>
    <div class="col-md-4"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Open Speakers</small><div class="stat-value mt-1">{{ $openSpeakers }}</div></div></div></div>
  </section>

  <section class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="mb-0">{{ ucfirst($roomType) }} Rooms</h5>
      <form method="get" class="d-flex gap-2 flex-wrap">
        <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search room, host, title">
        <select name="status" class="form-select">
          <option value="">Any status</option>
          @foreach(['scheduled','live','ended'] as $st)
            <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
          @endforeach
        </select>
        <button class="btn btn-light border">Filter</button>
      </form>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Room</th>
            <th>Host</th>
            <th>Status</th>
            <th>Open</th>
            <th>On Stage</th>
            <th>Peak</th>
            <th>Started</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rooms as $room)
            <tr>
              <td>
                <div class="fw-semibold">{{ $room->room_id }}</div>
                <div class="text-muted small">{{ $room->title ?: '—' }}</div>
              </td>
              <td>
                <div class="fw-semibold">{{ $room->host?->user?->name ?? '—' }}</div>
                <div class="text-muted small">{{ $room->host?->stage_name ?: '—' }}</div>
              </td>
              <td><span class="badge bg-light text-dark border">{{ ucfirst($room->status) }}</span></td>
              <td>{{ $room->open_participant_count ?? 0 }}</td>
              <td>{{ (int) ($room->open_host_count ?? 0) + (int) ($room->open_speaker_count ?? 0) }}</td>
              <td>{{ number_format((int) $room->peak_viewers) }}</td>
              <td>{{ optional($room->started_at)->format('d M Y H:i') ?: '—' }}</td>
              <td class="text-end">
                <a class="btn btn-sm btn-light border" href="{{ request()->routeIs('admin.*') ? route('admin.agencies.' . $roomType . '-rooms.show', ['agency' => $agency->id, 'live_room' => $room->id]) : route('agency.' . $roomType . '-rooms.show', $room) }}">View</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No {{ $roomType }} rooms found.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="d-flex justify-content-end">{{ $rooms->links() }}</div>
    </div>
  </section>
@endsection
