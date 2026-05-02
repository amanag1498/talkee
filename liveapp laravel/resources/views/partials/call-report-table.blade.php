@php
  $calls = $report['calls'];
  $summary = $report['summary'];
  $filters = $report['filters'];
  $earnings = $report['earnings'];
  $activeTab = request('tab', 'all');
  $schemaReady = $report['schema_ready'] ?? true;
  $setupMessage = $report['setup_message'] ?? null;
  $layout = $layout ?? 'default';
  $isAdminStyle = in_array($layout, ['admin', 'agency'], true);
  $reportingLabel = $layout === 'agency' ? 'Agency Reporting' : 'Admin Reporting';
  $tabMeta = [
    'all' => 'Full call ledger across the platform',
    'active' => 'Calls currently in progress',
    'completed' => 'Ended calls with settled billing',
    'missed_rejected' => 'Calls that did not complete',
    'host_earnings' => 'Host-wise revenue leaderboard',
    'agency_earnings' => 'Agency-wise revenue leaderboard',
  ];
  $currentTabDescription = $tabMeta[$activeTab] ?? 'Call activity and earnings';
  $topHost = $earnings['hosts']->first();
  $topAgency = $earnings['agencies']->first();
  $filterKeys = ['date_from', 'date_to', 'type', 'status', 'host_id', 'agency_id'];
  $activeFiltersCount = collect($filterKeys)->filter(fn ($key) => filled(request($key)))->count();
  $statusBreakdown = [
    'accepted' => $summary['active_calls'] ?? 0,
    'ended' => $summary['completed_calls'] ?? 0,
    'failed_group' => $summary['missed_rejected_calls'] ?? 0,
  ];
  $statusTotal = max(array_sum($statusBreakdown), 1);
  $globalAudioRate = (int) config('calls.audio_coin_rate_per_minute');
  $globalVideoRate = (int) config('calls.video_coin_rate_per_minute');
  $globalMinimumBalance = (int) config('calls.minimum_balance_to_start_call');
@endphp

@if(!$schemaReady && $setupMessage)
  <div class="alert alert-warning">
    {{ $setupMessage }}
  </div>
@endif

@if($isAdminStyle)
  <div class="card call-admin-hero mb-4">
    <div class="card-body p-4 p-lg-5">
      <div class="row align-items-center g-4">
        <div class="col-lg-7">
          <div class="d-flex align-items-center gap-2 mb-3">
            <span class="badge bg-light text-dark">{{ $reportingLabel }}</span>
            <span class="metric-chip"><i class="ti ti-filter"></i>{{ $activeFiltersCount }} active filters</span>
            <span class="metric-chip"><i class="ti ti-layout-kanban"></i>{{ $tabs[$activeTab] ?? ucfirst(str_replace('_', ' ', $activeTab)) }}</span>
          </div>
          <h3 class="mb-2 text-white">{{ $scopeLabel }}</h3>
          <p class="mb-0 text-white-50">{{ $currentTabDescription }}</p>
        </div>
        <div class="col-lg-5">
          <div class="row g-3">
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-phone-call me-1"></i>Total Calls</span>
                <strong>{{ number_format($summary['total_calls']) }}</strong>
              </div>
            </div>
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-coins me-1"></i>Coins</span>
                <strong>{{ number_format($summary['total_coins_charged']) }}</strong>
              </div>
            </div>
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-clock-hour-4 me-1"></i>Minutes</span>
                <strong>{{ number_format($summary['total_minutes']) }}</strong>
              </div>
            </div>
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-building-bank me-1"></i>Platform</span>
                <strong>{{ number_format($summary['total_platform_earnings']) }}</strong>
              </div>
            </div>
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-headphones me-1"></i>Audio Rate</span>
                <strong>{{ number_format($globalAudioRate) }}/min</strong>
              </div>
            </div>
            <div class="col-6">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-video me-1"></i>Video Rate</span>
                <strong>{{ number_format($globalVideoRate) }}/min</strong>
              </div>
            </div>
            <div class="col-12">
              <div class="metric-chip w-100 justify-content-between">
                <span><i class="ti ti-coins me-1"></i>Minimum Balance</span>
                <strong>{{ number_format($globalMinimumBalance) }}</strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
@endif

<div class="row g-3 mb-4">
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Total Calls</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['total_calls']) }}</div>
          <div class="text-muted small mt-2">All request states included</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-light text-primary"><i class="ti ti-phone"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Active Calls</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['active_calls']) }}</div>
          <div class="text-muted small mt-2">Accepted and still running</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-success-subtle text-success"><i class="ti ti-activity-heartbeat"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Completed Calls</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['completed_calls']) }}</div>
          <div class="text-muted small mt-2">Ended and billable sessions</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-info-subtle text-info"><i class="ti ti-checks"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Missed / Rejected</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['missed_rejected_calls']) }}</div>
          <div class="text-muted small mt-2">Missed, rejected, or failed</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-danger-subtle text-danger"><i class="ti ti-phone-x"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Total Minutes</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['total_minutes']) }}</div>
          <div class="text-muted small mt-2">Billed minutes across calls</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-warning-subtle text-warning"><i class="ti ti-clock"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <small class="text-muted">Coins Charged</small>
          <div class="fs-3 fw-semibold mt-1">{{ number_format($summary['total_coins_charged']) }}</div>
          <div class="text-muted small mt-2">Total caller coin spend</div>
        </div>
        @if($isAdminStyle)
          <span class="icon-wrap bg-primary-subtle text-primary"><i class="ti ti-coins"></i></span>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-2">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body">
        <small class="text-muted">Host Earnings</small>
        <div class="fs-4 fw-semibold mt-1">{{ number_format($summary['total_host_earnings']) }}</div>
      </div>
    </div>
  </div>
  <div class="col-md-6 col-xl-2">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body">
        <small class="text-muted">Agency Earnings</small>
        <div class="fs-4 fw-semibold mt-1">{{ number_format($summary['total_agency_earnings']) }}</div>
      </div>
    </div>
  </div>
  <div class="col-md-12 col-xl-2">
    <div class="card {{ $isAdminStyle ? 'call-admin-card call-admin-kpi' : '' }}">
      <div class="card-body">
        <small class="text-muted">Platform Earnings</small>
        <div class="fs-4 fw-semibold mt-1">{{ number_format($summary['total_platform_earnings']) }}</div>
      </div>
    </div>
  </div>
</div>

@if($isAdminStyle)
  <div class="row g-3 mb-4">
    <div class="col-lg-4">
      <div class="call-admin-insight">
        <div class="text-muted small mb-2">Top Host</div>
        <div class="fw-semibold fs-5">{{ $topHost?->host?->user?->name ?? 'No host data yet' }}</div>
        <div class="text-muted mt-2">Host earnings: {{ number_format((int) ($topHost->host_earning ?? 0)) }}</div>
        <div class="text-muted">Billable minutes: {{ number_format((int) ($topHost->billable_minutes ?? 0)) }}</div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="call-admin-insight">
        <div class="text-muted small mb-2">Top Agency</div>
        <div class="fw-semibold fs-5">{{ $topAgency?->agency?->name ?? 'No agency data yet' }}</div>
        <div class="text-muted mt-2">Agency earnings: {{ number_format((int) ($topAgency->agency_earning ?? 0)) }}</div>
        <div class="text-muted">Billable minutes: {{ number_format((int) ($topAgency->billable_minutes ?? 0)) }}</div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="call-admin-insight">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="text-muted small">Status Mix</div>
          <div class="small text-muted">{{ number_format($summary['total_calls']) }} total</div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between small mb-1"><span>Accepted</span><span>{{ number_format($statusBreakdown['accepted']) }}</span></div>
          <div class="progress" style="height: 8px;"><div class="progress-bar bg-success" style="width: {{ ($statusBreakdown['accepted'] / $statusTotal) * 100 }}%"></div></div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between small mb-1"><span>Ended</span><span>{{ number_format($statusBreakdown['ended']) }}</span></div>
          <div class="progress" style="height: 8px;"><div class="progress-bar bg-secondary" style="width: {{ ($statusBreakdown['ended'] / $statusTotal) * 100 }}%"></div></div>
        </div>
        <div>
          <div class="d-flex justify-content-between small mb-1"><span>Missed / Rejected / Failed</span><span>{{ number_format($statusBreakdown['failed_group']) }}</span></div>
          <div class="progress" style="height: 8px;"><div class="progress-bar bg-danger" style="width: {{ ($statusBreakdown['failed_group'] / $statusTotal) * 100 }}%"></div></div>
        </div>
      </div>
    </div>
  </div>
@endif

<div class="card {{ $isAdminStyle ? 'call-admin-card' : '' }}">
  <div class="card-header border-0 pb-0">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
      <div>
        <h5 class="mb-1">{{ $scopeLabel }}</h5>
        <div class="text-muted">{{ $currentTabDescription }}</div>
      </div>
      <div class="d-flex gap-2">
        @isset($exportRoute)
          <a class="btn btn-primary" href="{{ $exportRoute }}"><i class="ti ti-download me-1"></i>Export CSV</a>
        @endisset
        @if($activeFiltersCount > 0)
          <a class="btn btn-light border" href="{{ request()->url() }}?tab={{ $activeTab }}">Clear Filters</a>
        @endif
      </div>
    </div>
    <form method="get" class="mb-3">
      <input type="hidden" name="tab" value="{{ $activeTab }}">
      <div class="{{ $isAdminStyle ? 'call-admin-filter-grid' : 'row g-2' }}">
        <div>
          <label class="form-label">From</label>
          <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
        </div>
        <div>
          <label class="form-label">To</label>
          <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
        </div>
        <div>
          <label class="form-label">Call Type</label>
          <select name="type" class="form-select">
            <option value="">All types</option>
            <option value="audio" @selected(request('type') === 'audio')>Audio</option>
            <option value="video" @selected(request('type') === 'video')>Video</option>
          </select>
        </div>
        <div>
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All statuses</option>
            @foreach(['requested','ringing','accepted','rejected','missed','ended','failed'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
        </div>
        @if($filters)
          <div>
            <label class="form-label">Host</label>
            <select name="host_id" class="form-select">
              <option value="">All hosts</option>
              @foreach($filters['hosts'] as $host)
                <option value="{{ $host->id }}" @selected((int) request('host_id') === (int) $host->id)>{{ $host->user?->name ?? ('Host #' . $host->id) }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="form-label">Agency</label>
            <select name="agency_id" class="form-select">
              <option value="">All agencies</option>
              @foreach($filters['agencies'] as $agency)
                <option value="{{ $agency->id }}" @selected((int) request('agency_id') === (int) $agency->id)>{{ $agency->name }}</option>
              @endforeach
            </select>
          </div>
        @endif
      </div>
      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-dark"><i class="ti ti-filter me-1"></i>Apply Filters</button>
      </div>
    </form>
  </div>
  @isset($tabs)
    <div class="card-body border-top border-bottom">
      @if($isAdminStyle)
        <div class="call-admin-tabbar">
        @foreach($tabs as $key => $label)
          <a class="tab-pill {{ $activeTab === $key ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['tab' => $key]) }}">
            {{ $label }}
          </a>
        @endforeach
        </div>
      @else
      <ul class="nav nav-tabs">
        @foreach($tabs as $key => $label)
          <li class="nav-item">
            <a class="nav-link {{ $activeTab === $key ? 'active' : '' }}"
               href="{{ request()->fullUrlWithQuery(['tab' => $key]) }}">
              {{ $label }}
            </a>
          </li>
        @endforeach
      </ul>
      @endif
    </div>
  @endisset
  <div class="card-body table-responsive">
    @if($activeTab === 'host_earnings')
      <table class="table align-middle {{ $isAdminStyle ? 'call-admin-table' : '' }}">
        <thead class="table-light">
          <tr>
            <th>Host</th>
            <th>Total Coins</th>
            <th>Minutes</th>
            <th>Duration</th>
            <th>Host Earnings</th>
          </tr>
        </thead>
        <tbody>
          @forelse($earnings['hosts'] as $row)
            <tr>
              <td>{{ $row->host?->user?->name ?? ('Host #' . $row->host_id) }}</td>
              <td>{{ number_format($row->total_coins) }}</td>
              <td>{{ number_format($row->billable_minutes) }}</td>
              <td>{{ number_format($row->duration_seconds) }}</td>
              <td>{{ number_format($row->host_earning) }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No earnings found.</td></tr>
          @endforelse
        </tbody>
      </table>
    @elseif($activeTab === 'agency_earnings')
      <table class="table align-middle {{ $isAdminStyle ? 'call-admin-table' : '' }}">
        <thead class="table-light">
          <tr>
            <th>Agency</th>
            <th>Total Coins</th>
            <th>Minutes</th>
            <th>Duration</th>
            <th>Agency Earnings</th>
          </tr>
        </thead>
        <tbody>
          @forelse($earnings['agencies'] as $row)
            <tr>
              <td>{{ $row->agency?->name ?? ('Agency #' . $row->agency_id) }}</td>
              <td>{{ number_format($row->total_coins) }}</td>
              <td>{{ number_format($row->billable_minutes) }}</td>
              <td>{{ number_format($row->duration_seconds) }}</td>
              <td>{{ number_format($row->agency_earning) }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No earnings found.</td></tr>
          @endforelse
        </tbody>
      </table>
    @else
      <table class="table align-middle {{ $isAdminStyle ? 'call-admin-table' : '' }}">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Caller</th>
            <th>Receiver</th>
            <th>Host</th>
            <th>Agency</th>
            <th>Type</th>
            <th>Status</th>
            <th>End Reason</th>
            <th>Rate / min</th>
            <th>Duration</th>
            <th>Minutes</th>
            <th>Coins</th>
            <th>Host</th>
            <th>Agency</th>
            <th>Platform</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          @forelse($calls as $call)
            <tr>
              <td>{{ $call->id }}</td>
              <td>{{ $call->caller?->name ?? '—' }}</td>
              <td>{{ $call->receiver?->name ?? '—' }}</td>
              <td>{{ $call->host?->user?->name ?? '—' }}</td>
              <td>{{ $call->agency?->name ?? '—' }}</td>
              <td><span class="call-badge-soft {{ strtolower($call->type) }}">{{ ucfirst($call->type) }}</span></td>
              <td><span class="call-badge-soft {{ strtolower($call->status) }}">{{ ucfirst(str_replace('_', ' ', $call->status)) }}</span></td>
              <td>{{ $call->end_reason ? ucfirst(str_replace('_', ' ', $call->end_reason)) : '—' }}</td>
              <td>{{ number_format((int) $call->coin_rate_per_minute) }}</td>
              <td>{{ $call->duration_seconds }}</td>
              <td>{{ $call->billable_minutes }}</td>
              <td>{{ number_format($call->total_coins_charged) }}</td>
              <td>{{ number_format($call->host_earning) }}</td>
              <td>{{ number_format($call->agency_earning) }}</td>
              <td>{{ number_format($call->platform_earning) }}</td>
              <td>{{ $call->created_at?->format('d M Y H:i') }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="16" class="text-center text-muted py-4">No calls found.</td>
            </tr>
          @endforelse
        </tbody>
      </table>

      {{ $calls->links() }}
    @endif
  </div>
</div>
