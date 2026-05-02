@extends('layouts.admin-berry')
@section('title','Agency Reports')

@php
  $kpis = $report['kpis'];
  $charts = $report['charts'];
  $rows = $report['weekly_rows'];
  $from = $report['from'];
  $to = $report['to'];
@endphp

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-building-bank"></i>Agency Analytics</span>
        <h1 class="admin-page-title">Agency Reports</h1>
        <p class="admin-page-subtitle">Agency-level call, minutes, coin, and earning visibility backed by stored call and ledger data.</p>
      </div>
      <div class="col-lg-4">
        <form class="row g-2 justify-content-lg-end" method="get">
          <div class="col-6">
            <input type="date" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
          </div>
          <div class="col-6">
            <input type="date" name="to" class="form-control" value="{{ $to->format('Y-m-d') }}">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-primary">Apply Range</button>
          </div>
        </form>
      </div>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Total Agencies</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['total_agencies']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Active Agencies</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['active_agencies']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Total Hosts</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['total_hosts']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Total Calls</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['total_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Total Minutes</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['total_minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Total Coins</small><div class="fs-3 fw-semibold mt-1">{{ number_format($kpis['total_coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio / Video</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['audio_calls']) }} / {{ number_format($kpis['video_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Completed / Failed</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['completed_calls']) }} / {{ number_format($kpis['failed_calls']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Host Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['host_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['agency_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Rooms</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['live_rooms']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Minutes</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['live_minutes']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Gift Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['live_gift_coins']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Agency Earnings</small><div class="fs-5 fw-semibold mt-1">{{ number_format($kpis['live_agency_earnings']) }}</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Calls Over Time</h5></div>
        <div class="card-body"><div id="agency-calls-over-time" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Call Type Split</h5></div>
        <div class="card-body"><div id="agency-call-type" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Earnings Over Time</h5></div>
        <div class="card-body"><div id="agency-earnings-over-time" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Call Status Split</h5></div>
        <div class="card-body"><div id="agency-call-status" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Top Agencies</h5></div>
        <div class="card-body"><div id="agency-top-agencies" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Top Hosts</h5></div>
        <div class="card-body"><div id="agency-top-hosts" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Live Rooms Over Time</h5></div>
        <div class="card-body"><div id="agency-live-rooms-over-time" style="height: 320px;"></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Live Gift Earnings</h5></div>
        <div class="card-body"><div id="agency-live-gifts-over-time" style="height: 320px;"></div></div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Weekly Agency Table</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Agency</th>
            <th>Hosts</th>
            <th>Calls</th>
            <th>Minutes</th>
            <th>Coins</th>
            <th>Earnings</th>
            <th>Live Rooms</th>
            <th>Live Gift Coins</th>
            <th>Top Host</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            <tr>
              <td>
                <div class="fw-semibold">{{ $row['agency']->name }}</div>
                <div class="text-muted small">{{ $row['agency']->owner?->name }}</div>
              </td>
              <td>{{ number_format($row['host_count']) }}</td>
              <td>{{ number_format($row['calls']) }}</td>
              <td>{{ number_format($row['minutes']) }}</td>
              <td>{{ number_format($row['coins']) }}</td>
              <td>{{ number_format($row['earnings']) }}</td>
              <td>{{ number_format($row['live_rooms']) }}</td>
              <td>{{ number_format($row['live_gift_coins']) }}</td>
              <td>{{ $row['top_host'] ?? '—' }}</td>
              <td class="text-end">
                <a href="{{ route('admin.reports.agencies.show', ['agency' => $row['agency']->id, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="btn btn-sm btn-light border">View Detail</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center text-muted py-4">No agency data in this range.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
  (() => {
    const callsOverTime = @json($charts['calls_over_time']);
    const earningsOverTime = @json($charts['earnings_over_time']);
    const topAgencies = @json($charts['top_agencies']);
    const topHosts = @json($charts['top_hosts']);
    const callType = @json($charts['call_type']);
    const callStatus = @json($charts['call_status']);
    const liveRoomsOverTime = @json($charts['live_rooms_over_time']);
    const liveGiftsOverTime = @json($charts['live_gifts_over_time']);

    new ApexCharts(document.querySelector('#agency-calls-over-time'), {
      chart: { type: 'line', height: 320, toolbar: { show: false } },
      series: [{ name: 'Calls', data: callsOverTime.values }],
      xaxis: { categories: callsOverTime.labels },
      stroke: { curve: 'smooth', width: 3 },
      colors: ['#0f766e']
    }).render();

    new ApexCharts(document.querySelector('#agency-earnings-over-time'), {
      chart: { type: 'line', height: 320, toolbar: { show: false } },
      series: [
        { name: 'Coins', data: earningsOverTime.coins },
        { name: 'Host Earnings', data: earningsOverTime.host_earnings },
        { name: 'Agency Earnings', data: earningsOverTime.agency_earnings }
      ],
      xaxis: { categories: earningsOverTime.labels },
      stroke: { curve: 'smooth', width: 3 },
      colors: ['#0f766e', '#f59e0b', '#2563eb']
    }).render();

    new ApexCharts(document.querySelector('#agency-top-agencies'), {
      chart: { type: 'bar', height: 320, toolbar: { show: false } },
      series: [{ name: 'Coins', data: topAgencies.coins }],
      xaxis: { categories: topAgencies.labels },
      colors: ['#0f766e']
    }).render();

    new ApexCharts(document.querySelector('#agency-top-hosts'), {
      chart: { type: 'bar', height: 320, toolbar: { show: false } },
      series: [{ name: 'Coins', data: topHosts.coins }],
      xaxis: { categories: topHosts.labels },
      colors: ['#7c3aed']
    }).render();

    new ApexCharts(document.querySelector('#agency-call-type'), {
      chart: { type: 'pie', height: 320 },
      series: callType.values,
      labels: callType.labels,
      colors: ['#0f766e', '#f59e0b']
    }).render();

    new ApexCharts(document.querySelector('#agency-call-status'), {
      chart: { type: 'pie', height: 320 },
      series: callStatus.values,
      labels: callStatus.labels,
      colors: ['#94a3b8', '#38bdf8', '#0f766e', '#22c55e', '#f97316', '#ef4444', '#a855f7']
    }).render();

    new ApexCharts(document.querySelector('#agency-live-rooms-over-time'), {
      chart: { type: 'line', height: 320, toolbar: { show: false } },
      series: [{ name: 'Live Rooms', data: liveRoomsOverTime.values }],
      xaxis: { categories: liveRoomsOverTime.labels },
      stroke: { curve: 'smooth', width: 3 },
      colors: ['#7c3aed']
    }).render();

    new ApexCharts(document.querySelector('#agency-live-gifts-over-time'), {
      chart: { type: 'line', height: 320, toolbar: { show: false } },
      series: [
        { name: 'Gift Coins', data: liveGiftsOverTime.gift_coins },
        { name: 'Host Earnings', data: liveGiftsOverTime.host_earnings },
        { name: 'Agency Earnings', data: liveGiftsOverTime.agency_earnings }
      ],
      xaxis: { categories: liveGiftsOverTime.labels },
      stroke: { curve: 'smooth', width: 3 },
      colors: ['#f59e0b', '#0f766e', '#2563eb']
    }).render();
  })();
</script>
@endsection
