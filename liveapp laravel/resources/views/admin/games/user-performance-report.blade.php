@extends('layouts.admin-berry')

@section('content')
@php
  $filters = $report['filters'] ?? [];
  $summary = $report['summary'] ?? [];
  $rows = $report['rows'] ?? null;
@endphp

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
      <h3 class="mb-1">{{ $gameName }} User Report</h3>
      <p class="text-muted mb-0">{{ $gameDescription }}</p>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route($dashboardRoute) }}" class="btn btn-light border">Back to Dashboard</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <form method="get" action="{{ route($reportRoute) }}" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Period</label>
          <select name="period" class="form-select">
            @foreach(['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'this_month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom'] as $value => $label)
              <option value="{{ $value }}" @selected(($filters['period'] ?? '7d') === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Start date</label>
          <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">End date</label>
          <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">User search</label>
          <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="User name, email, or ID">
        </div>
        <div class="col-md-1">
          <label class="form-label">Rows</label>
          <select name="per_page" class="form-select">
            @foreach([25, 50, 100] as $size)
              <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary flex-fill">Apply</button>
          <a href="{{ route($reportRoute) }}" class="btn btn-light border">Reset</a>
        </div>
      </form>
      <div class="small text-muted mt-3">
        Reporting window: {{ $filters['label'] ?? 'Last 7 days' }}
        @if(!empty($filters['start_date']) && !empty($filters['end_date']))
          ({{ $filters['start_date'] }} to {{ $filters['end_date'] }})
        @endif
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-2">
      <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Active Users</div><div class="fs-4 fw-semibold">{{ number_format((int) ($summary['active_users_count'] ?? 0)) }}</div></div></div>
    </div>
    <div class="col-md-6 col-xl-2">
      <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total Bet Placed</div><div class="fs-4 fw-semibold">{{ number_format((int) ($summary['total_bet_amount'] ?? 0)) }}</div></div></div>
    </div>
    <div class="col-md-6 col-xl-2">
      <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total Win Given</div><div class="fs-4 fw-semibold">{{ number_format((int) ($summary['total_win_amount'] ?? 0)) }}</div></div></div>
    </div>
    <div class="col-md-6 col-xl-2">
      <div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Refunded</div><div class="fs-4 fw-semibold">{{ number_format((int) ($summary['refunded_amount'] ?? 0)) }}</div></div></div>
    </div>
    <div class="col-md-6 col-xl-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small">Profit</div>
          @php $profit = (int) ($summary['profit_amount'] ?? 0); @endphp
          <div class="fs-4 fw-semibold {{ $profit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($profit) }}</div>
          <div class="small text-muted">Profit = total bet placed - win given - refunds</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Per-user performance</h5>
        <div class="small text-muted">For the selected reporting window</div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>User</th>
              <th class="text-end">Bet Count</th>
              <th class="text-end">Total Bet Placed</th>
              <th class="text-end">Win Count</th>
              <th class="text-end">Total Win Given</th>
              <th class="text-end">Refunded</th>
              <th class="text-end">Profit</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rows ?? [] as $row)
              @php $rowProfit = (int) $row->profit_amount; @endphp
              <tr>
                <td>
                  <div class="fw-semibold">{{ $row->name ?: 'User #' . $row->user_id }}</div>
                  <div class="small text-muted">#{{ $row->user_id }}{{ $row->email ? ' · ' . $row->email : '' }}</div>
                </td>
                <td class="text-end">{{ number_format((int) $row->total_bets_count) }}</td>
                <td class="text-end">{{ number_format((int) $row->total_bet_amount) }}</td>
                <td class="text-end">{{ number_format((int) $row->total_wins_count) }}</td>
                <td class="text-end">{{ number_format((int) $row->total_win_amount) }}</td>
                <td class="text-end">{{ number_format((int) $row->refunded_amount) }}</td>
                <td class="text-end {{ $rowProfit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($rowProfit) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center text-muted py-5">No user activity found for the selected period.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if($rows)
        <div class="mt-3">
          {{ $rows->links() }}
        </div>
      @endif
    </div>
  </div>
</div>
@endsection
