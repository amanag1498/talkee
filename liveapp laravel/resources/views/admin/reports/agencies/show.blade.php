@extends('layouts.admin-berry')
@section('title', $report['agency']->name . ' Report')

@php
  $agency = $report['agency'];
  $summary = $report['summary'];
  $hosts = $report['hosts_table'];
  $weeks = $report['weekly_breakdown'];
  $recentCalls = $report['recent_calls'];
  $from = $report['from'];
  $to = $report['to'];
  $payoutReport = $report['payout_report'] ?? null;
@endphp

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-building"></i>Agency Detail</span>
        <h1 class="admin-page-title">{{ $agency->name }}</h1>
        <p class="admin-page-subtitle">
          Owner: {{ $agency->owner?->name ?? '—' }} · Hosts: {{ number_format($summary['hosts']) }} ·
          Contact: {{ $agency->contact_email ?: '—' }}
        </p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.reports.agencies', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="btn btn-light border">Back to Agency Reports</a>
          <a href="{{ route('admin.agencies.dashboard', $agency) }}" class="btn btn-outline-secondary">Open Agency Dashboard</a>
          <form method="post" action="{{ route('admin.agency-payout-reports.generate') }}" class="d-inline">
            @csrf
            <input type="hidden" name="start" value="{{ $from->format('Y-m-d') }}">
            <input type="hidden" name="end" value="{{ $to->format('Y-m-d') }}">
            <input type="hidden" name="agency_id" value="{{ $agency->id }}">
            <button class="btn btn-outline-primary">Generate Draft</button>
          </form>
          @if($payoutReport)
            <a href="{{ route('admin.agency-payout-reports.show', $payoutReport) }}" class="btn btn-light border">View Draft</a>
            <a href="{{ route('admin.agency-payout-reports.export', $payoutReport) }}" class="btn btn-outline-secondary">PDF</a>
            @if($payoutReport->status === 'approved' && !$payoutReport->published_at)
              <form method="post" action="{{ route('admin.agency-payout-reports.publish', $payoutReport) }}" class="d-inline">
                @csrf
                <button class="btn btn-outline-primary">Publish</button>
              </form>
            @endif
            @if($payoutReport->status === 'approved' && $payoutReport->published_at)
              <form method="post" action="{{ route('admin.agency-payout-reports.mark-paid', $payoutReport) }}" class="d-inline">
                @csrf
                <button class="btn btn-success">Mark Paid</button>
              </form>
            @endif
            @if($payoutReport->status !== 'paid')
              <form method="post" action="{{ route('admin.agency-payout-reports.destroy', $payoutReport) }}" class="d-inline" onsubmit="return confirm('Delete this payout report draft? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger">Delete</button>
              </form>
            @endif
          @else
            <a href="{{ route('admin.agency-payout-reports.index', ['agency_id' => $agency->id, 'date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')]) }}" class="btn btn-light border">Open Drafts</a>
          @endif
          <a href="{{ route('admin.agencies.edit', $agency) }}" class="btn btn-primary">Edit Agency</a>
        </div>
      </div>
    </div>
  </section>

  @if($payoutReport)
    <section class="row g-3 mb-3">
      <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Payout Draft Status</small><div class="fs-5 fw-semibold mt-1">{{ ucwords(str_replace('_', ' ', $payoutReport->status)) }}</div></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Visibility</small><div class="fs-5 fw-semibold mt-1">{{ $payoutReport->published_at ? 'Published' : 'Draft only' }}</div></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Coins To Be Paid</small><div class="fs-5 fw-semibold mt-1">{{ number_format($payoutReport->total_coins_to_be_paid) }}</div></div></div></div>
      <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Published At</small><div class="fs-5 fw-semibold mt-1">{{ $payoutReport->published_at ? optional($payoutReport->published_at)->format('d M Y H:i') : 'Not yet' }}</div></div></div></div>
    </section>
  @endif

  <section class="row g-3">
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Calls</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Minutes</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Coins</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Earnings</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['agency_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio / Video</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['audio_calls']) }} / {{ number_format($summary['video_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Completed / Failed</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['completed_calls']) }} / {{ number_format($summary['failed_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Host Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['host_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Blocked</small><div class="fs-5 fw-semibold mt-1">{{ $agency->is_blocked ? 'Yes' : 'No' }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Rooms</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_rooms']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Minutes</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Gift Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_gift_coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Agency Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['live_agency_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gift Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($summary['pk_gift_coins']) }}</div><div class="text-muted small mt-1">Agency {{ number_format($summary['pk_agency_earnings']) }} · {{ number_format($summary['pk_event_count']) }} events</div></div></div></div>
  </section>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Hosts</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Host</th>
            <th>Followers</th>
            <th>Calls</th>
            <th>Minutes</th>
                <th>Coins</th>
                <th>Host Earnings</th>
                <th>Agency Earnings</th>
                <th>Live Rooms</th>
                <th>Live Gift Coins</th>
                <th>PK Coins / Events</th>
              </tr>
        </thead>
        <tbody>
          @forelse($hosts as $row)
            <tr>
              <td>
                <div class="fw-semibold">
                  <a href="{{ route('admin.reports.hosts.show', ['host' => $row['host']->id, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="text-decoration-none">
                    {{ $row['host']->user?->name ?? $row['host']->stage_name }}
                  </a>
                </div>
                <div class="text-muted small">{{ $row['host']->stage_name }}</div>
              </td>
              <td>{{ number_format($row['host']->followers_count ?? 0) }}</td>
              <td>{{ number_format($row['calls']) }}</td>
              <td>{{ number_format($row['minutes']) }}</td>
              <td>{{ number_format($row['coins']) }}</td>
              <td>{{ number_format($row['host_earnings']) }}</td>
              <td>{{ number_format($row['agency_earnings']) }}</td>
              <td>{{ number_format($row['live_rooms']) }}</td>
              <td>{{ number_format($row['live_gift_coins']) }}</td>
              <td>{{ number_format($row['pk_gift_coins']) }} / {{ number_format($row['pk_event_count']) }}</td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center text-muted py-4">No hosts attached to this agency.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Weekly Breakdown</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>Week Start</th>
                <th>Calls</th>
                <th>Minutes</th>
                <th>Coins</th>
                <th>Agency Earnings</th>
                <th>Live Rooms</th>
                <th>Live Gift Coins</th>
                <th>PK Coins / Events</th>
              </tr>
            </thead>
            <tbody>
              @forelse($weeks as $week)
                <tr>
                  <td>{{ \Carbon\Carbon::parse($week['week_start'])->format('d M Y') }}</td>
                  <td>{{ number_format($week['calls']) }}</td>
                  <td>{{ number_format($week['minutes']) }}</td>
                  <td>{{ number_format($week['coins']) }}</td>
                  <td>{{ number_format($week['agency_earnings']) }}</td>
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
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Calls</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Caller</th>
                <th>Host</th>
                <th>Type</th>
                <th>Status</th>
                <th>Coins</th>
              </tr>
            </thead>
            <tbody>
              @forelse($recentCalls as $call)
                <tr>
                  <td>#{{ $call->id }}</td>
                  <td>{{ $call->caller?->name }}</td>
                  <td>{{ $call->host?->user?->name }}</td>
                  <td>{{ ucfirst($call->type) }}</td>
                  <td>{{ ucfirst($call->status) }}</td>
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
    <div class="col-lg-12">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Recent Live Rooms</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>Room</th>
                <th>Host</th>
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
                  <td>{{ $room->host?->user?->name }}</td>
                  <td>{{ ucfirst($room->status) }}</td>
                  <td>{{ optional($room->started_at)?->format('d M Y H:i') ?: '—' }}</td>
                  <td>{{ optional($room->ended_at)?->format('d M Y H:i') ?: '—' }}</td>
                  <td>{{ $room->duration_minutes !== null ? number_format($room->duration_minutes) . ' min' : '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No live room activity in this range.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>
@endsection
