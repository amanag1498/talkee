@extends('layouts.agency-berry')
@section('title', ($host->user?->name ?? $host->stage_name ?? 'Host Detail'))
@section('page_intro', 'Detailed host performance across calls, live rooms, payout items, and earnings inside your agency.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $hostsIndexRoute ?? route('agency.hosts.index') }}">Back to Hosts</a>
  <a class="btn btn-primary" href="{{ $callsRoute ?? route('agency.calls.index', ['host_id' => $host->id]) }}">Filter Call Reports</a>
@endsection

@section('content')
  @php
    $summary = $detail['summary'];
    $availability = $host->user?->hostAvailability;
    $isOnline = in_array($availability?->socket_status, ['online'], true) || in_array($availability?->manual_status, ['online'], true);
  @endphp

  <section class="row g-3">
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Calls</small><div class="stat-value mt-1">{{ number_format($summary['call_count']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Minutes</small><div class="stat-value mt-1">{{ number_format($summary['total_minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Gross Total</small><div class="stat-value mt-1">{{ number_format($summary['gross_total']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Followers</small><div class="stat-value mt-1">{{ number_format($summary['followers']) }}</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Host Summary</h5></div>
        <div class="card-body">
          <div class="fw-semibold">{{ $host->user?->name ?? '—' }}</div>
          <div class="text-muted small mb-3">{{ $host->stage_name ?: '—' }} · {{ $host->user?->email ?? '—' }}</div>
          <div class="mb-2">
            <span class="agency-status-dot {{ $isOnline ? 'online' : 'offline' }}"></span>
            <span class="ms-1">{{ $isOnline ? 'Online' : 'Offline' }}</span>
          </div>
          <div class="row g-3">
            <div class="col-6"><div class="text-muted small">Country</div><div class="fw-semibold">{{ $host->country ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">City</div><div class="fw-semibold">{{ $host->city ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Audio Rate</div><div class="fw-semibold">{{ number_format((int) $host->audio_call_rate_per_minute) }}</div></div>
            <div class="col-6"><div class="text-muted small">Video Rate</div><div class="fw-semibold">{{ number_format((int) $host->video_call_rate_per_minute) }}</div></div>
          </div>
          @if($host->bio)
            <hr>
            <div class="text-muted small mb-1">Bio</div>
            <div>{{ $host->bio }}</div>
          @endif
        </div>
      </div>
    </div>
    <div class="col-xl-7">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Earnings Summary</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle mb-0">
            <tbody>
              <tr><th>Video Room Minutes</th><td>{{ number_format($summary['video_room_minutes']) }}</td><th>Video Gift Gross</th><td>{{ number_format($summary['video_gift_gross']) }}</td></tr>
              <tr><th>Audio Room Minutes</th><td>{{ number_format($summary['audio_room_minutes']) }}</td><th>Audio Gift Gross</th><td>{{ number_format($summary['audio_gift_gross']) }}</td></tr>
              <tr><th>Video Call Min / Gross</th><td>{{ number_format($summary['video_call_minutes']) }} / {{ number_format($summary['video_call_gross']) }}</td><th>Audio Call Min / Gross</th><td>{{ number_format($summary['audio_call_minutes']) }} / {{ number_format($summary['audio_call_gross']) }}</td></tr>
              <tr><th>Host Payout</th><td>{{ number_format($summary['host_payout']) }} <span class="text-muted">({{ number_format($summary['host_payout_percentage'], 2) }}%)</span></td><th>Agency Payout</th><td>{{ number_format($summary['agency_payout']) }} <span class="text-muted">({{ number_format($summary['agency_payout_percentage'], 2) }}%)</span></td></tr>
              <tr><th>Total Payout</th><td>{{ number_format($summary['total_payout']) }}</td><th>Live Rooms</th><td>{{ number_format($summary['live_rooms']) }} / {{ number_format($summary['live_rooms_active']) }} live</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-xl-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Calls</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light"><tr><th>ID</th><th>Caller</th><th>Type</th><th>Status</th><th>Coins</th></tr></thead>
            <tbody>
              @forelse($detail['recentCalls'] as $call)
                <tr>
                  <td>#{{ $call->id }}</td>
                  <td>{{ $call->caller?->name ?? '—' }}</td>
                  <td>{{ ucfirst($call->type) }}</td>
                  <td>{{ ucfirst($call->status) }}</td>
                  <td>{{ number_format((int) $call->total_coins_charged) }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No call records yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Weekly Payout Line Items</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light"><tr><th>Week</th><th>Gross</th><th>Agency</th><th>Host</th><th>Final</th></tr></thead>
            <tbody>
              @forelse($detail['recentPayoutItems'] as $item)
                <tr>
                  <td>{{ optional($item->report?->period_start)->format('d M Y') ?: '—' }}</td>
                  <td>{{ number_format((int) $item->gross_earnings) }}</td>
                  <td>{{ number_format((int) $item->agency_commission) }}</td>
                  <td>{{ number_format((int) $item->host_share) }}</td>
                  <td>{{ number_format((int) $item->final_payable) }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No payout items yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Recent Live Rooms</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Room</th><th>Status</th><th>Started</th><th>Ended</th></tr></thead>
        <tbody>
          @forelse($detail['recentLiveRooms'] as $room)
            <tr>
              <td>{{ $room->title ?: $room->room_id }}</td>
              <td>{{ ucfirst($room->status) }}</td>
              <td>{{ optional($room->started_at)->format('d M Y H:i') ?: '—' }}</td>
              <td>{{ optional($room->ended_at)->format('d M Y H:i') ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No live room activity yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
