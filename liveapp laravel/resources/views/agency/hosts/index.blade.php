@extends('layouts.agency-berry')
@section('title', 'Host Roster')
@section('page_intro', 'Agency-scoped host roster with current status, call performance, and live earnings.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $overviewRoute ?? route('agency.dashboard') }}">Back to Dashboard</a>
  <a class="btn btn-primary" href="{{ $callsRoute ?? route('agency.calls.index') }}">Open Call Reports</a>
@endsection

@section('content')
  @php
    $filters = $filters ?? ['period' => 'weekly', 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'label' => ''];
  @endphp

  <section class="row g-3">
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Total Hosts</small><div class="stat-value mt-1">{{ number_format($summary['host_count'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Active Hosts</small><div class="stat-value mt-1">{{ number_format($summary['active_host_count'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Live Now</small><div class="stat-value mt-1">{{ number_format($summary['live_host_count'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Blocked Hosts</small><div class="stat-value mt-1">{{ number_format($summary['blocked_host_count'] ?? 0) }}</div></div></div></div>
  </section>

  <section class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h5 class="mb-0">Host Directory</h5>
        <span class="text-muted small">{{ $agency->name }} · {{ $filters['label'] ?? 'Agency-scoped host performance' }}</span>
      </div>
    </div>
    <div class="card-body border-bottom">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">View</label>
          <select name="period" class="form-select">
            <option value="daily" @selected(($filters['period'] ?? 'weekly') === 'daily')>Daily</option>
            <option value="weekly" @selected(($filters['period'] ?? 'weekly') === 'weekly')>Weekly</option>
            <option value="custom" @selected(($filters['period'] ?? 'weekly') === 'custom')>Custom</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary w-100">Apply</button>
          <a href="{{ route('agency.hosts.index') }}" class="btn btn-light border">Reset</a>
        </div>
      </form>
    </div>
    <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
          <tr>
            <th>Host</th>
            <th>Status</th>
            <th>Video Room Min</th>
            <th>Video Gifts</th>
            <th>Audio Room Min</th>
            <th>Audio Gifts</th>
            <th>PK Gross / Events</th>
            <th>Video Call Min / Earn</th>
            <th>Audio Call Min / Earn</th>
            <th>Gross</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($hosts as $host)
            @php
              $availability = $host->user?->hostAvailability;
              $isOnline = in_array($availability?->socket_status, ['online'], true) || in_array($availability?->manual_status, ['online'], true);
            @endphp
            <tr>
              <td>
                <div class="fw-semibold">{{ $host->user?->name ?? '—' }}</div>
                <div class="text-muted small">{{ $host->stage_name ?: '—' }} · {{ $host->user?->email ?? '—' }}</div>
              </td>
              <td>
                <span class="agency-status-dot {{ $isOnline ? 'online' : 'offline' }}"></span>
                <span class="ms-1">{{ $isOnline ? 'Online' : 'Offline' }}</span>
              </td>
              <td>{{ number_format((int) $host->dashboard_video_room_minutes) }}</td>
              <td>{{ number_format((int) $host->dashboard_video_gift_gross) }}</td>
              <td>{{ number_format((int) $host->dashboard_audio_room_minutes) }}</td>
              <td>{{ number_format((int) $host->dashboard_audio_gift_gross) }}</td>
              <td>{{ number_format((int) $host->dashboard_pk_gross) }} / {{ number_format((int) $host->dashboard_pk_event_count) }}</td>
              <td>{{ number_format((int) $host->dashboard_video_call_minutes) }} / {{ number_format((int) $host->dashboard_video_call_gross) }}</td>
              <td>{{ number_format((int) $host->dashboard_audio_call_minutes) }} / {{ number_format((int) $host->dashboard_audio_call_gross) }}</td>
              <td>{{ number_format((int) $host->dashboard_total_gross) }}</td>
              <td>
                <a href="{{ request()->routeIs('admin.*') ? route('admin.agencies.hosts.show', ['agency' => $agency->id, 'host' => $host->id]) : route('agency.hosts.show', $host) }}" class="btn btn-sm btn-light border">View</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="11" class="text-center text-muted py-4">No hosts attached to this agency for the selected period.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="d-flex justify-content-end">{{ $hosts->links() }}</div>
    </div>
  </section>
@endsection
