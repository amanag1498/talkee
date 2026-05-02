@extends('layouts.admin-berry')
@section('title', 'Leaderboard Reports')

@section('content')
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
          <div>
            <h4 class="mb-1">Leaderboard Reports</h4>
            <div class="text-muted">{{ $rangeLabel }}</div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.reports.leaderboards.export', array_merge(request()->query(), ['dataset' => 'users'])) }}" class="btn btn-light border">Export Users CSV</a>
            <a href="{{ route('admin.reports.leaderboards.export', array_merge(request()->query(), ['dataset' => 'hosts'])) }}" class="btn btn-light border">Export Hosts CSV</a>
            <a href="{{ route('admin.reports.leaderboards.export', array_merge(request()->query(), ['dataset' => 'agencies'])) }}" class="btn btn-primary">Export Agencies CSV</a>
          </div>
        </div>

        <form method="get" class="row g-3">
          <div class="col-md-2">
            <label class="form-label">Week Picker</label>
            <input type="week" name="week" value="{{ $selectedWeek }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">From</label>
            <input type="date" name="from" value="{{ $fromDate->format('Y-m-d') }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">To</label>
            <input type="date" name="to" value="{{ $toDate->format('Y-m-d') }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">User Search</label>
            <input type="text" name="user_q" value="{{ $userQuery }}" class="form-control" placeholder="Name, email, id">
          </div>
          <div class="col-md-2">
            <label class="form-label">Host Search</label>
            <input type="text" name="host_q" value="{{ $hostQuery }}" class="form-control" placeholder="Host, stage, agency">
          </div>
          <div class="col-md-1">
            <label class="form-label">Agency</label>
            <input type="text" name="agency_q" value="{{ $agencyQuery }}" class="form-control" placeholder="Name">
          </div>
          <div class="col-md-1">
            <label class="form-label">Top N</label>
            <input type="number" name="limit" min="1" max="200" value="{{ $limit }}" class="form-control">
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Apply Filters</button>
            <a href="{{ route('admin.reports.leaderboards') }}" class="btn btn-light border">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0">Weekly Top Users</h6>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Level</th>
              <th>Gift Spend</th>
              <th>Call Spend</th>
              <th>Subscription Spend</th>
              <th>Entry Spend</th>
              <th>Total Weekly Spend</th>
            </tr>
          </thead>
          <tbody>
          @forelse($users as $row)
            <tr>
              <td class="fw-semibold">{{ $row['rank'] }}</td>
              <td>
                <div class="fw-semibold">{{ $row['name'] }}</div>
                <small class="text-muted">{{ $row['email'] ?: 'User #'.$row['id'] }}</small>
              </td>
              <td>{{ $row['level'] ? 'L'.$row['level'] : '—' }}</td>
              <td>{{ number_format($row['gift_coins']) }}</td>
              <td>{{ number_format($row['call_coins']) }}</td>
              <td>{{ number_format($row['subscription_coins']) }}</td>
              <td>{{ number_format($row['entry_coins']) }}</td>
              <td class="fw-semibold">{{ number_format($row['total_coins']) }}</td>
            </tr>
          @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No user leaderboard data in this range.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0">Weekly Top Hosts</h6>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Host</th>
              <th>Agency</th>
              <th>Gift Coins</th>
              <th>Call Coins</th>
              <th>Total Gross</th>
            </tr>
          </thead>
          <tbody>
          @forelse($hosts as $row)
            <tr>
              <td class="fw-semibold">{{ $row['rank'] }}</td>
              <td>
                <div class="fw-semibold">{{ $row['name'] }}</div>
                <small class="text-muted">Host #{{ $row['host_id'] }} · User #{{ $row['host_user_id'] }}</small>
              </td>
              <td>{{ $row['agency_name'] }}</td>
              <td>{{ number_format($row['gift_coins']) }}</td>
              <td>{{ number_format($row['call_coins']) }}</td>
              <td class="fw-semibold">{{ number_format($row['total_coins']) }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No host leaderboard data in this range.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0">Weekly Top Agencies</h6>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Agency</th>
              <th>Host Count</th>
              <th>Gift Coins</th>
              <th>Call Coins</th>
              <th>Total Gross</th>
            </tr>
          </thead>
          <tbody>
          @forelse($agencies as $row)
            <tr>
              <td class="fw-semibold">{{ $row['rank'] }}</td>
              <td>
                <div class="fw-semibold">{{ $row['name'] }}</div>
                <small class="text-muted">Agency #{{ $row['agency_id'] }}</small>
              </td>
              <td>{{ number_format($row['host_count']) }}</td>
              <td>{{ number_format($row['gift_coins']) }}</td>
              <td>{{ number_format($row['call_coins']) }}</td>
              <td class="fw-semibold">{{ number_format($row['total_coins']) }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No agency leaderboard data in this range.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection
