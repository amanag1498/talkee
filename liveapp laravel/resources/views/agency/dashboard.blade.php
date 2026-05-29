@extends('layouts.agency-berry')
@section('title', 'Agency Dashboard')
@section('page_intro', 'Agency-side operations view for host roster, live performance, call earnings, and weekly payout readiness.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $hostsIndexRoute ?? route('agency.hosts.index') }}">Hosts</a>
  <a class="btn btn-light border" href="{{ $callsRoute ?? route('agency.calls.index') }}">Call Reports</a>
  @if($agency)
    <a class="btn btn-light border" href="{{ $walletRoute ?? (request()->routeIs('admin.*') ? route('admin.agencies.wallet.show', $agency) : route('agency.wallet.show')) }}">Wallet</a>
  @endif
  <a class="btn btn-primary" href="{{ $payoutReportsRoute ?? route('agency.payout-reports.index') }}">Weekly Payout Reports</a>
@endsection

@section('content')
  @if(!$agency)
    <div class="alert alert-warning">
      Your agency is not created yet. If you recently applied, wait for admin approval.
    </div>
  @else
    @php
      $summary = $dashboard['summary'] ?? [];
      $hosts = $dashboard['hosts'] ?? collect();
      $recentPayoutReports = $dashboard['recentPayoutReports'] ?? collect();
      $recentLiveRooms = $dashboard['recentLiveRooms'] ?? collect();
      $topHosts = $dashboard['topHosts'] ?? collect();
    @endphp

    <section class="row g-3">
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Total Hosts</small>
            <div class="stat-value mt-1">{{ number_format($summary['host_count'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Blocked: {{ number_format($summary['blocked_host_count'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Active Hosts</small>
            <div class="stat-value mt-1">{{ number_format($summary['active_host_count'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Live now: {{ number_format($summary['live_host_count'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Gross Total</small>
            <div class="stat-value mt-1">{{ number_format($summary['gross_total'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Host payout {{ number_format($summary['host_payout_total'] ?? 0) }} · Agency payout {{ number_format($summary['agency_payout_total'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Combined Payout</small>
            <div class="stat-value mt-1">{{ number_format($summary['combined_payout_total'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Avg host % {{ number_format((float) ($summary['host_payout_percentage'] ?? 0), 2) }} · Agency % {{ number_format((float) ($summary['agency_payout_percentage'] ?? 0), 2) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Video Activity</small>
            <div class="stat-value mt-1">{{ number_format($summary['video_room_minutes'] ?? 0) }} min</div>
            <div class="stat-meta mt-2">Calls {{ number_format($summary['video_call_minutes'] ?? 0) }} min · Gifts {{ number_format($summary['video_gift_gross'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Audio Activity</small>
            <div class="stat-value mt-1">{{ number_format($summary['audio_room_minutes'] ?? 0) }} min</div>
            <div class="stat-meta mt-2">Calls {{ number_format($summary['audio_call_minutes'] ?? 0) }} min · Gifts {{ number_format($summary['audio_gift_gross'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">PK Earnings</small>
            <div class="stat-value mt-1">{{ number_format($summary['pk_gross'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Events {{ number_format($summary['pk_event_count'] ?? 0) }} · Agency {{ number_format($summary['pk_agency_earnings'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Payout Reports</small>
            <div class="stat-value mt-1">{{ number_format($summary['payout_reports'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Approved unpaid: {{ number_format($summary['approved_unpaid_reports'] ?? 0) }}</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Approved Unpaid Amount</small>
            <div class="stat-value mt-1">{{ number_format($summary['approved_unpaid_amount'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Offline payout pending review/payment</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card agency-stat-card">
          <div class="card-body">
            <small class="text-muted">Agency Wallet</small>
            <div class="stat-value mt-1">{{ number_format($walletSummary['balance'] ?? 0) }}</div>
            <div class="stat-meta mt-2">Loaded {{ number_format($walletSummary['total_loaded'] ?? 0) }} · Sent {{ number_format($walletSummary['total_distributed'] ?? 0) }}</div>
          </div>
        </div>
      </div>
    </section>

    <section class="row g-3">
      <div class="col-xl-5">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="mb-0">Agency Summary</h5>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <div class="fw-semibold">{{ $agency->name }}</div>
              <div class="text-muted small">{{ $agency->legal_name ?: 'No legal name on file' }}</div>
            </div>
            <div class="row g-3">
              <div class="col-6">
                <div class="text-muted small">Owner</div>
                <div class="fw-semibold">{{ $agency->owner?->name ?? '—' }}</div>
                <div class="text-muted small">{{ $agency->owner?->email ?? '—' }}</div>
              </div>
              <div class="col-6">
                <div class="text-muted small">Contact</div>
                <div class="fw-semibold">{{ $agency->contact_phone ?: '—' }}</div>
                <div class="text-muted small">{{ $agency->contact_email ?: '—' }}</div>
              </div>
              <div class="col-6">
                <div class="text-muted small">Payout %</div>
                <div class="fw-semibold">{{ number_format((float) $agency->payout_percentage, 2) }}%</div>
              </div>
              <div class="col-6">
                <div class="text-muted small">Weekly Bonus</div>
                <div class="fw-semibold">{{ number_format((int) $agency->weekly_bonus) }}</div>
              </div>
            </div>
            @if($agency->notes)
              <hr>
              <div class="text-muted small mb-1">Notes</div>
              <div>{{ $agency->notes }}</div>
            @endif
          </div>
        </div>
      </div>
      <div class="col-xl-7">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="mb-0">Top Hosts By Gross Activity</h5>
          </div>
          <div class="card-body">
            <div class="agency-top-list">
              @forelse($topHosts as $row)
                <div class="agency-top-item">
                  <div>
                    <div class="fw-semibold">{{ $row['host']->user?->name ?? $row['host']->stage_name }}</div>
                    <div class="text-muted small">{{ $row['host']->stage_name ?: '—' }} · Calls: {{ number_format($row['call_count']) }} · PK {{ number_format($row['pk_event_count']) }}</div>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold">{{ number_format($row['gross']) }}</div>
                    <div class="text-muted small">Agency payout {{ number_format($row['agency_earnings']) }} · PK {{ number_format($row['pk_gross']) }}</div>
                  </div>
                </div>
              @empty
                <div class="text-muted">No host activity yet.</div>
              @endforelse
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Host Roster</h5>
        <span class="text-muted small">Agency-scoped host performance</span>
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
              <th>Host Payout</th>
              <th>Agency Payout</th>
              <th>Total Payout</th>
              <th>Joined</th>
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
                  <div class="fw-semibold">
                    <a href="{{ ($hostsIndexRoute ?? null) && request()->routeIs('admin.*') ? route('admin.agencies.hosts.show', ['agency' => $agency->id, 'host' => $host->id]) : route('agency.hosts.show', $host) }}" class="text-decoration-none">
                      {{ $host->user?->name ?? '—' }}
                    </a>
                  </div>
                  <div class="text-muted small">{{ $host->stage_name ?: '—' }} · {{ $host->user?->email ?? '' }}</div>
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
                <td>{{ number_format((float) $host->dashboard_host_payout_percentage, 2) }}% · {{ number_format((int) $host->dashboard_host_payout) }}</td>
                <td>{{ number_format((float) $host->dashboard_agency_payout_percentage, 2) }}% · {{ number_format((int) $host->dashboard_agency_payout) }}</td>
                <td>{{ number_format((int) $host->dashboard_total_payout) }}</td>
                <td>{{ optional($host->created_at)->format('d M Y') ?: '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="14" class="text-center text-muted py-4">No hosts attached to this agency.</td></tr>
            @endforelse
          </tbody>
        </table>
        @if(method_exists($hosts, 'links'))
          <div class="d-flex justify-content-end">{{ $hosts->links() }}</div>
        @endif
      </div>
    </section>

    <section class="row g-3">
      <div class="col-xl-6">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Weekly Payout Reports</h5>
            <a href="{{ $payoutReportsRoute ?? route('agency.payout-reports.index') }}" class="btn btn-sm btn-light border">View All</a>
          </div>
          <div class="card-body table-responsive">
            <table class="table align-middle">
              <thead class="table-light">
                <tr>
                  <th>Week</th>
                  <th>Final Payable</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                @forelse($recentPayoutReports as $report)
                  <tr>
                    <td>{{ optional($report->period_start)->format('d M Y') }} - {{ optional($report->period_end)->format('d M Y') }}</td>
                    <td>{{ number_format($report->final_payable) }}</td>
                    <td><span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $report->status)) }}</span></td>
                  </tr>
                @empty
                  <tr><td colspan="3" class="text-center text-muted py-4">No payout reports yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="col-xl-6">
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
                </tr>
              </thead>
              <tbody>
                @forelse($recentLiveRooms as $room)
                  <tr>
                    <td>{{ $room->title ?: $room->room_id }}</td>
                    <td>{{ $room->host?->user?->name ?? '—' }}</td>
                    <td>{{ ucfirst($room->status) }}</td>
                    <td>{{ optional($room->started_at)->format('d M Y H:i') ?: '—' }}</td>
                  </tr>
                @empty
                  <tr><td colspan="4" class="text-center text-muted py-4">No live room activity yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  @endif
@endsection
