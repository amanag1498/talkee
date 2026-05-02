@extends('layouts.agency-berry')
@section('title', 'Weekly Payout Reports')
@section('page_intro', 'Read-only weekly payout reports scoped to your agency, with offline payout tracking and host-level breakdowns.')

@section('content')
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All</option>
            @foreach($statuses as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Week Start</label>
          <input type="date" name="week_start" class="form-control" value="{{ request('week_start') }}">
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-outline-primary">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Reports</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Week</th>
            <th>Hosts</th>
            <th>Active Hosts</th>
            <th>Gross</th>
            <th>Final Payable</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse($reports as $report)
            <tr>
              <td>{{ optional($report->period_start)->format('d M Y') }} - {{ optional($report->period_end)->format('d M Y') }}</td>
              <td>{{ number_format($report->total_hosts) }}</td>
              <td>{{ number_format($report->active_hosts_count) }}</td>
              <td>{{ number_format($report->gross_earnings) }}</td>
              <td>{{ number_format($report->final_payable) }}</td>
              <td>{{ ucwords(str_replace('_', ' ', $report->status)) }}</td>
              <td class="text-end"><a href="{{ route('agency.payout-reports.show', $report) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No payout reports yet.</td></tr>
          @endforelse
        </tbody>
      </table>
      {{ $reports->links() }}
    </div>
  </div>
@endsection
