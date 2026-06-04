@extends('layouts.admin-berry')
@section('title', 'Weekly Agency Payout Reports')

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-cash-banknote"></i>Settlements</span>
        <h1 class="admin-page-title">Weekly Agency Payout Reports</h1>
        <p class="admin-page-subtitle">Weekly payable reports per agency with host-level breakdown, review states, and settlement status.</p>
      </div>
      <div class="col-lg-4">
        <form method="post" action="{{ route('admin.agency-payout-reports.generate') }}" class="card border-0 shadow-sm">
          @csrf
          <div class="card-body">
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small text-muted">Start</label>
                <input type="date" name="start" class="form-control" value="{{ request('date_from') }}">
              </div>
              <div class="col-6">
                <label class="form-label small text-muted">End</label>
                <input type="date" name="end" class="form-control" value="{{ request('date_to') }}">
              </div>
              <div class="col-12">
                <label class="form-label small text-muted">Agency</label>
                <select name="agency_id" class="form-select">
                  <option value="">All Agencies</option>
                  @foreach($agencies as $agency)
                    <option value="{{ $agency->id }}" @selected((string) request('agency_id') === (string) $agency->id)>{{ $agency->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 form-check mt-2 ms-1">
                <input type="checkbox" class="form-check-input" id="force-regenerate" name="force" value="1">
                <label class="form-check-label" for="force-regenerate">Force regenerate if unpaid</label>
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-primary">Generate Reports</button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </section>

  @if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <section class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Reports</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['reports']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Gross Earnings</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['gross_earnings']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Commission</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['agency_commission']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Final Payable</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['final_payable']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Published</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['published']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Paid</small><div class="fs-3 fw-semibold mt-1">{{ number_format($summary['paid']) }}</div></div></div></div>
  </section>

  <section class="card">
    <div class="card-header">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Agency</label>
          <select name="agency_id" class="form-select">
            <option value="">All</option>
            @foreach($agencies as $agency)
              <option value="{{ $agency->id }}" @selected((string) request('agency_id') === (string) $agency->id)>{{ $agency->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All</option>
            @foreach($statuses as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Week Start</label>
          <input type="date" name="week_start" class="form-control" value="{{ request('week_start') }}">
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-light border">Apply Filters</button>
        </div>
      </form>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Agency</th>
            <th>Week</th>
            <th>Hosts</th>
            <th>Active Hosts</th>
            <th>Gross</th>
            <th>Agency Comm.</th>
            <th>Deductions</th>
            <th>Final Payable</th>
            <th>Status</th>
            <th>Agency Visibility</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($reports as $report)
            <tr>
              <td>
                <div class="fw-semibold">{{ $report->agency?->name ?? '—' }}</div>
                <div class="text-muted small">{{ $report->agency?->owner?->name ?? '—' }}</div>
              </td>
              <td>{{ optional($report->period_start)->format('d M Y') }} - {{ optional($report->period_end)->format('d M Y') }}</td>
              <td>{{ number_format($report->total_hosts) }}</td>
              <td>{{ number_format($report->active_hosts_count) }}</td>
              <td>{{ number_format($report->gross_earnings) }}</td>
              <td>{{ number_format($report->agency_commission) }}</td>
              <td>{{ number_format($report->deductions) }}</td>
              <td>{{ number_format($report->final_payable) }}</td>
              <td><span class="badge bg-light text-dark border">{{ ucwords(str_replace('_', ' ', $report->status)) }}</span></td>
              <td>
                @if($report->published_at)
                  <div class="fw-semibold text-success">Published</div>
                  <div class="text-muted small">{{ optional($report->published_at)->format('d M Y H:i') }}</div>
                @else
                  <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Draft Only</span>
                @endif
              </td>
              <td class="text-end">
                <a href="{{ route('admin.agency-payout-reports.show', $report) }}" class="btn btn-sm btn-light border">View</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="11" class="text-center text-muted py-4">No payout reports found.</td></tr>
          @endforelse
        </tbody>
      </table>
      {{ $reports->links() }}
    </div>
  </section>
</div>
@endsection
