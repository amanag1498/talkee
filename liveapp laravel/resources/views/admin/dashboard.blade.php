@extends('layouts.admin-berry')
@section('title','Talkieo Overview')
@section('page_actions')
  <a href="{{ route('admin.calls.index') }}" class="btn btn-primary"><i class="ti ti-phone me-1"></i>Call Reports</a>
  <a href="{{ route('admin.users.index') }}" class="btn btn-light border"><i class="ti ti-users me-1"></i>Users</a>
@endsection

@section('content')
<style>
  .admin-home-stat {
    position: relative;
    overflow: hidden;
    min-height: 150px;
  }

  .admin-home-stat::after {
    content: "";
    position: absolute;
    right: -24px;
    bottom: -24px;
    width: 110px;
    height: 110px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
  }

  .admin-home-stat .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .admin-home-panel {
    border: 1px solid rgba(148, 163, 184, 0.14);
    background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.9));
  }
</style>

<div class="row g-3">

  <div class="col-12">
    <div class="card admin-home-panel">
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-xl-8">
            <div class="d-flex flex-wrap gap-2 mb-3">
              <span class="badge bg-light text-dark"><i class="ti ti-shield-check me-1"></i>Operations</span>
              <span class="badge bg-light text-dark"><i class="ti ti-activity me-1"></i>Realtime</span>
              <span class="badge bg-light text-dark"><i class="ti ti-coins me-1"></i>Monetization</span>
            </div>
            <h4 class="mb-2">Platform status and moderation overview</h4>
            <p class="text-muted mb-0">This dashboard now consolidates requests, platform health, wallet supply, and recent operational traffic into a cleaner control surface.</p>
          </div>
          <div class="col-xl-4">
            <div class="row g-2">
              <div class="col-6">
                <div class="border rounded-4 p-3 h-100 bg-white">
                  <div class="text-muted small">Pending Work</div>
                  <div class="fs-4 fw-bold">{{ $stats['pendingAgency'] + $stats['pendingHost'] + $stats['pendingEnroll'] }}</div>
                </div>
              </div>
              <div class="col-6">
                <div class="border rounded-4 p-3 h-100 bg-white">
                  <div class="text-muted small">Health Endpoints</div>
                  <div class="fs-4 fw-bold">3</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- ===== Stat cards (wrap into 2 rows on smaller screens) ===== --}}
  <div class="col-xl-3 col-md-6">
    <div class="card bg-secondary-dark text-white overflow-hidden admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-white-50">Pending Agency</small>
            <div class="fs-3 fw-semibold">{{ $stats['pendingAgency'] }}</div>
            <div class="small text-white-50 mt-2">Awaiting approval action</div>
          </div>
          <div class="stat-icon bg-white bg-opacity-10"><i class="ti ti-building text-white"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card bg-primary-dark text-white overflow-hidden admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-white-50">Pending Host</small>
            <div class="fs-3 fw-semibold">{{ $stats['pendingHost'] }}</div>
            <div class="small text-white-50 mt-2">Host onboarding queue</div>
          </div>
          <div class="stat-icon bg-white bg-opacity-10"><i class="ti ti-user-star text-white"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card bg-warning text-dark overflow-hidden admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-dark-50">Pending Enroll</small>
            <div class="fs-3 fw-semibold">{{ $stats['pendingEnroll'] }}</div>
            <div class="small text-dark opacity-75 mt-2">Agency enrollment review</div>
          </div>
          <div class="stat-icon bg-light-warning"><i class="ti ti-user-plus text-warning"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Total Users</small>
            <div class="fs-3 fw-semibold">{{ $stats['totalUsers'] }}</div>
            <div class="small text-muted mt-2">Registered user accounts</div>
          </div>
          <div class="stat-icon bg-light-secondary"><i class="ti ti-users text-secondary"></i></div>
        </div>
      </div>
    </div>
  </div>

  {{-- More totals --}}
  <div class="col-xl-3 col-md-6">
    <div class="card admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Total Agencies</small>
            <div class="fs-3 fw-semibold">{{ $stats['totalAgencies'] }}</div>
            <div class="small text-muted mt-2">Agency entities onboarded</div>
          </div>
          <div class="stat-icon bg-light-primary"><i class="ti ti-building text-primary"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Total Hosts</small>
            <div class="fs-3 fw-semibold">{{ $stats['totalHosts'] }}</div>
            <div class="small text-muted mt-2">Hosts available to platform</div>
          </div>
          <div class="stat-icon bg-light-secondary"><i class="ti ti-user-star text-secondary"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card bg-dark text-white admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-white-50">Coin Supply</small>
            <div class="fs-3 fw-semibold">{{ number_format($stats['coinSupply']) }}</div>
            <div class="small text-white-50 mt-2">Users {{ number_format($stats['userCoinSupply'] ?? 0) }} · Agencies {{ number_format($stats['agencyCoinSupply'] ?? 0) }}</div>
          </div>
          <div class="stat-icon bg-white bg-opacity-10"><i class="ti ti-coins text-white"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-3 col-md-6">
    <div class="card admin-home-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Blocked Users</small>
            <div class="fs-3 fw-semibold">{{ $stats['blockedUsers'] }}</div>
            <div class="small text-muted mt-2">Accounts under restriction</div>
          </div>
          <div class="stat-icon bg-light-danger"><i class="ti ti-ban text-danger"></i></div>
        </div>
      </div>
    </div>
  </div>

  {{-- Operational metrics --}}
  <div class="col-12">
    <h6 class="mb-1 mt-2"><i class="ti ti-activity me-2"></i>Operational Metrics</h6>
  </div>

  <div class="col-xl-4 col-md-6">
    <div class="card border border-danger-subtle">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Auth Failures</small>
            <div class="fs-3 fw-semibold text-danger">{{ number_format((int) ($opsMetrics['auth_failures'] ?? 0)) }}</div>
          </div>
          <div class="avtar avtar-lg bg-light-danger"><i class="ti ti-shield-x text-danger"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6">
    <div class="card border border-primary-subtle">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Room Joins</small>
            <div class="fs-3 fw-semibold text-primary">{{ number_format((int) ($opsMetrics['room_joins'] ?? 0)) }}</div>
          </div>
          <div class="avtar avtar-lg bg-light-primary"><i class="ti ti-users-group text-primary"></i></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 col-md-6">
    <div class="card border border-warning-subtle">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <small class="text-muted">Queue Failures</small>
            <div class="fs-3 fw-semibold text-warning">{{ number_format((int) ($opsMetrics['queue_failures'] ?? 0)) }}</div>
          </div>
          <div class="avtar avtar-lg bg-light-warning"><i class="ti ti-alert-triangle text-warning"></i></div>
        </div>
      </div>
    </div>
  </div>

  {{-- Health check params --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-heartbeat me-2"></i>Health Checks</h6>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle mb-0">
          <tbody>
            <tr>
              <th class="text-muted" style="width: 260px;">Live Endpoint</th>
              <td><code>{{ $healthConfig['liveEndpoint'] }}</code></td>
            </tr>
            <tr>
              <th class="text-muted">Ready Endpoint</th>
              <td><code>{{ $healthConfig['readyEndpoint'] }}</code></td>
            </tr>
            <tr>
              <th class="text-muted">Metrics Endpoint</th>
              <td><code>{{ $healthConfig['metricsEndpoint'] }}</code></td>
            </tr>
            <tr>
              <th class="text-muted">Metrics Auth Header</th>
              <td><code>{{ $healthConfig['metricsHeader'] }}</code></td>
            </tr>
            <tr>
              <th class="text-muted">Metrics Key Configured</th>
              <td>
                <span class="badge {{ $healthConfig['metricsKeyConfigured'] ? 'bg-success' : 'bg-warning text-dark' }}">
                  {{ $healthConfig['metricsKeyConfigured'] ? 'Yes' : 'No' }}
                </span>
              </td>
            </tr>
            <tr>
              <th class="text-muted">Expose Dependency Errors</th>
              <td>
                <span class="badge {{ $healthConfig['exposeErrors'] ? 'bg-danger' : 'bg-secondary' }}">
                  {{ $healthConfig['exposeErrors'] ? 'Enabled' : 'Disabled' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- ===== Latest tables ===== --}}

  {{-- Latest Agency Requests --}}
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-building me-2"></i>Latest Agency Requests</h6>
        <a href="{{ route('admin.agency-requests.index') }}" class="btn btn-sm btn-light border">View all</a>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th><th>User</th><th>Agency</th><th>Status</th><th>Applied</th>
            </tr>
          </thead>
          <tbody>
          @forelse($latestAgency as $r)
            <tr>
              <td>{{ $r->id }}</td>
              <td>
                <div class="d-flex flex-column">
                  <span class="fw-medium">{{ $r->user?->name ?? '—' }}</span>
                  <small class="text-muted">{{ $r->user?->email ?? '' }}</small>
                </div>
              </td>
              <td>{{ $r->agency_name }}</td>
              <td>
                <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                  {{ ucfirst($r->status) }}
                </span>
              </td>
              <td>{{ $r->created_at?->format('d M Y') }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-3">No recent items.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- Latest Host Requests --}}
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-user-star me-2"></i>Latest Host Requests</h6>
        <a href="{{ route('admin.host-requests.index') }}" class="btn btn-sm btn-light border">View all</a>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th><th>User</th><th>Stage</th><th>Status</th><th>Applied</th>
            </tr>
          </thead>
          <tbody>
          @forelse($latestHost as $r)
            <tr>
              <td>{{ $r->id }}</td>
              <td>
                <div class="d-flex flex-column">
                  <span class="fw-medium">{{ $r->user?->name ?? '—' }}</span>
                  <small class="text-muted">{{ $r->user?->email ?? '' }}</small>
                </div>
              </td>
              <td>{{ $r->stage_name ?: '—' }}</td>
              <td>
                <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                  {{ ucfirst($r->status) }}
                </span>
              </td>
              <td>{{ $r->created_at?->format('d M Y') }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-3">No recent items.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- Latest Enroll Requests (full width) --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-user-plus me-2"></i>Latest Enroll Requests</h6>
        <a href="{{ route('admin.enroll-requests.index') }}" class="btn btn-sm btn-light border">View all</a>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Host</th><th>Agency</th><th>Status</th><th>Applied</th>
            </tr>
          </thead>
          <tbody>
          @forelse($latestEnroll as $r)
            <tr>
              <td>{{ $r->id }}</td>
              <td>
                <div class="d-flex flex-column">
                  <span class="fw-medium">{{ $r->hostUser?->name ?? '—' }}</span>
                  <small class="text-muted">{{ $r->hostUser?->email ?? '' }}</small>
                </div>
              </td>
              <td>{{ $r->agency?->name ?? '—' }}</td>
              <td>
                <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                  {{ ucfirst($r->status) }}
                </span>
              </td>
              <td>{{ $r->created_at?->format('d M Y') }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-3">No recent items.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  {{-- ===== Latest Ledger ===== --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-coins me-2"></i>Latest Coin Transactions</h6>
        <a href="{{ route('admin.wallets.index') }}" class="btn btn-sm btn-light border">View wallets</a>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th><th>User</th><th>Type</th><th>Amount</th><th>Ref</th><th>When</th>
            </tr>
          </thead>
          <tbody>
          @forelse($latestTx as $tx)
            <tr>
              <td>{{ $tx->id }}</td>
              <td>
                <div class="d-flex flex-column">
                  <span class="fw-medium">{{ $tx->wallet?->user?->name ?? '—' }}</span>
                  <small class="text-muted">{{ $tx->wallet?->user?->email ?? '' }}</small>
                </div>
              </td>
              <td>
                <span class="badge {{ $tx->type==='credit' ? 'bg-success' : 'bg-danger' }}">{{ ucfirst($tx->type) }}</span>
              </td>
              <td class="fw-semibold">{{ number_format($tx->amount) }}</td>
              <td><code>{{ $tx->reference ?? '-' }}</code></td>
              <td>{{ $tx->created_at?->diffForHumans() }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-3">No transactions yet.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
@endsection
