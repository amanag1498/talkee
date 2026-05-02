@extends('layouts.agency-berry')
@section('title', ucfirst($roomType) . ' Room')
@section('page_intro', 'Read-only room detail for agency operations, participation, and gift earnings visibility.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ request()->routeIs('admin.*') ? route('admin.agencies.' . $roomType . '-rooms.index', $agency) : route('agency.' . $roomType . '-rooms.index') }}">Back to {{ ucfirst($roomType) }} Rooms</a>
  <a class="btn btn-primary" href="{{ $pkBattlesRoute ?? route('agency.pk-battles.index') }}">PK Battles</a>
@endsection

@section('content')
  <section class="row g-3 mb-3">
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Participants</small><div class="stat-value mt-1">{{ number_format($stats['participants_open']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">On Stage</small><div class="stat-value mt-1">{{ number_format($stats['host_open'] + $stats['speaker_open']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Gift Coins</small><div class="stat-value mt-1">{{ number_format($stats['gift_coins']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Agency Earnings</small><div class="stat-value mt-1">{{ number_format($stats['gift_agency_earnings']) }}</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Room Summary</h5></div>
        <div class="card-body">
          <div class="fw-semibold">{{ $live_room->title ?: $live_room->room_id }}</div>
          <div class="text-muted small mb-3">{{ $live_room->room_id }} · {{ ucfirst($live_room->status) }}</div>
          <div class="row g-3">
            <div class="col-6"><div class="text-muted small">Host</div><div class="fw-semibold">{{ $live_room->host?->user?->name ?? '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Peak Viewers</div><div class="fw-semibold">{{ number_format((int) $live_room->peak_viewers) }}</div></div>
            <div class="col-6"><div class="text-muted small">Started</div><div class="fw-semibold">{{ optional($live_room->started_at)->format('d M Y H:i') ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Ended</div><div class="fw-semibold">{{ optional($live_room->ended_at)->format('d M Y H:i') ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Duration</div><div class="fw-semibold">{{ $stats['duration_min'] !== null ? number_format($stats['duration_min']) . ' min' : '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Type</div><div class="fw-semibold">{{ ucfirst($roomType) }}</div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-7">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Gift Earnings</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle mb-0">
            <tbody>
              <tr><th>Gift Events</th><td>{{ number_format($stats['gift_events']) }}</td><th>Gift Coins</th><td>{{ number_format($stats['gift_coins']) }}</td></tr>
              <tr><th>Host Earnings</th><td>{{ number_format($stats['gift_host_earnings']) }}</td><th>Agency Earnings</th><td>{{ number_format($stats['gift_agency_earnings']) }}</td></tr>
              <tr><th>Platform Earnings</th><td>{{ number_format($stats['gift_platform_earnings']) }}</td><th>Open Participants</th><td>{{ number_format($stats['participants_open']) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="card mt-3">
    <div class="card-header"><h5 class="mb-0">Recent Gifts</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Sender</th><th>Gift</th><th>Qty</th><th>Total Coins</th><th>Created</th></tr></thead>
        <tbody>
          @forelse($live_room->gifts->take(20) as $gift)
            <tr>
              <td>{{ $gift->sender?->name ?? '—' }}</td>
              <td>{{ $gift->gift?->name ?? '—' }}</td>
              <td>{{ number_format((int) $gift->quantity) }}</td>
              <td>{{ number_format((int) $gift->total_coins) }}</td>
              <td>{{ optional($gift->created_at)->format('d M Y H:i') ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No gifts recorded for this room.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
