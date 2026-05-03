@extends('layouts.admin-berry')
@section('title', ($report['host']->user?->name ?? $report['host']->stage_name ?? ('Host #'.$report['host']->id)) . ' Report')

@php
  $host = $report['host'];
  $summary = $report['summary'];
  $from = $report['from'];
  $to = $report['to'];
@endphp

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-microphone-2"></i>Host Detail</span>
        <h1 class="admin-page-title">{{ $host->user?->name ?? $host->stage_name ?? ('Host #'.$host->id) }}</h1>
        <p class="admin-page-subtitle">
          Agency: {{ $host->agency?->name ?? 'Independent' }} · Stage Name: {{ $host->stage_name ?: '—' }} ·
          Followers: {{ number_format($summary['followers']) }}
        </p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.reports.hosts', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="btn btn-light border">Back to Host Reports</a>
          <a href="{{ route('admin.hosts.edit', $host) }}" class="btn btn-primary">Edit Host</a>
        </div>
      </div>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Calls</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Minutes</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Call Coins</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['call_coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Host Earnings</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['host_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio / Video</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['audio_calls']) }} / {{ number_format($summary['video_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Completed / Failed</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['completed_calls']) }} / {{ number_format($summary['failed_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Rooms</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_rooms']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Gift Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_gift_coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gift Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['pk_gift_coins']) }}</div><div class="text-muted small mt-1">Events {{ number_format($summary['pk_event_count']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Minutes</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Host Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_host_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Host / Agency</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['pk_host_earnings']) }} / {{ number_format($summary['pk_agency_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['agency_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Participants</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['participants_total']) }} / {{ number_format($summary['participants_unique']) }}</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Weekly Breakdown</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>Week Start</th>
                <th>Calls</th>
                <th>Audio / Video</th>
                <th>Minutes</th>
                <th>Call Coins</th>
                <th>Live Rooms</th>
                <th>Live Gift Coins</th>
                <th>PK Coins / Events</th>
              </tr>
            </thead>
            <tbody>
              @forelse($report['weekly_breakdown'] as $week)
                <tr>
                  <td>{{ \Carbon\Carbon::parse($week['week_start'])->format('d M Y') }}</td>
                  <td>{{ number_format($week['calls']) }}</td>
                  <td>{{ number_format($week['audio_calls']) }} / {{ number_format($week['video_calls']) }}</td>
                  <td>{{ number_format($week['minutes']) }}</td>
                  <td>{{ number_format($week['call_coins']) }}</td>
                  <td>{{ number_format($week['live_rooms']) }}</td>
                  <td>{{ number_format($week['live_gift_coins']) }}</td>
                  <td>{{ number_format($week['pk_gift_coins']) }} / {{ number_format($week['pk_event_count']) }}</td>
                </tr>
              @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No weekly data.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Followers</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>User</th>
                <th>Online Notify</th>
                <th>Available Notify</th>
                <th>Followed</th>
              </tr>
            </thead>
            <tbody>
              @forelse($report['followers'] as $follow)
                <tr>
                  <td>{{ $follow->user?->name ?? ('User #'.$follow->user_id) }}</td>
                  <td>{{ $follow->notify_when_online ? 'Yes' : 'No' }}</td>
                  <td>{{ $follow->notify_when_available ? 'Yes' : 'No' }}</td>
                  <td>{{ optional($follow->created_at)?->format('d M Y H:i') ?: '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-center text-muted py-4">No followers yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Calls</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Caller</th>
                <th>Type</th>
                <th>Status</th>
                <th>Minutes</th>
                <th>Coins</th>
              </tr>
            </thead>
            <tbody>
              @forelse($report['recent_calls'] as $call)
                <tr>
                  <td>#{{ $call->id }}</td>
                  <td>{{ $call->caller?->name ?? ('User #'.$call->caller_id) }}</td>
                  <td>{{ ucfirst($call->type) }}</td>
                  <td>{{ ucfirst($call->status) }}</td>
                  <td>{{ number_format((int) $call->billable_minutes) }}</td>
                  <td>{{ number_format((int) $call->total_coins_charged) }}</td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No recent calls.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Live Rooms</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>Room</th>
                <th>Status</th>
                <th>Started</th>
                <th>Ended</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              @forelse($report['recent_live_rooms'] as $room)
                <tr>
                  <td>{{ $room->title ?: $room->room_id }}</td>
                  <td>{{ ucfirst($room->status) }}</td>
                  <td>{{ optional($room->started_at)?->format('d M Y H:i') ?: '—' }}</td>
                  <td>{{ optional($room->ended_at)?->format('d M Y H:i') ?: '—' }}</td>
                  <td>{{ $room->duration_minutes !== null ? number_format($room->duration_minutes) . ' min' : '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No live rooms in this range.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>
@endsection
