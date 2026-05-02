@extends('layouts.admin-berry')
@section('title','Host Reports')

@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-report-analytics me-2"></i>Host Reports</h5>
    <form class="d-flex flex-wrap gap-2" method="get">
      <select name="host_id" class="form-select">
        <option value="">All hosts</option>
        @foreach($hosts as $h)
          <option value="{{ $h->id }}" @selected($hostId==$h->id)>{{ $h->user?->name }} (ID: {{ $h->id }})</option>
        @endforeach
      </select>
      <select name="range" class="form-select">
        <option value="daily"  @selected($range==='daily')>Daily</option>
        <option value="weekly" @selected($range==='weekly')>Weekly</option>
      </select>
      <input type="date" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
      <input type="date" name="to"   class="form-control" value="{{ $to->format('Y-m-d') }}">
      <button class="btn btn-light border">Apply</button>
      <a class="btn btn-primary" href="{{ route('admin.reports.hosts.csv', request()->query()) }}"><i class="ti ti-download me-1"></i>CSV</a>
    </form>
  </div>

  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          @if($range==='weekly')
            <th>Week</th>
          @else
            <th>Date</th>
          @endif
          <th>Host</th>
          <th>Rooms</th>
          <th>Duration (min)</th>
          <th>Participants</th>
          <th>Unique</th>
          <th>Call Coins</th>
          <th>Gift Coins</th>
          <th>Gross Coins</th>
          <th>Host %</th>
          @if($range==='weekly')
            <th>Weekly Bonus</th>
          @endif
          <th>Host Payable</th>
          <th>Agency %</th>
          <th>Agency Payable</th>
          <th>Gift Events</th>
        </tr>
      </thead>
      <tbody>
      @forelse($rows as $r)
        <tr>
          @if($range==='weekly')
            <td>{{ \Carbon\Carbon::parse($r['week_start'])->format('d M Y') }}</td>
          @else
            <td>{{ \Carbon\Carbon::parse($r['date'])->format('d M Y') }}</td>
          @endif
          @php($reportHost = $hosts->firstWhere('id', $r['host_id']))
          <td>
            <a href="{{ route('admin.reports.hosts.show', ['host' => $r['host_id'], 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]) }}" class="fw-semibold text-decoration-none">
              {{ $reportHost?->user?->name ?? 'Host #'.$r['host_id'] }}
            </a>
            <div class="text-muted small">{{ $reportHost?->agency?->name ?? 'Independent' }}</div>
          </td>
          <td>{{ $r['rooms'] }}</td>
          <td>{{ $r['duration_min'] }}</td>
          <td>{{ $r['participants_total'] }}</td>
          <td>{{ $r['participants_unique'] }}</td>
          <td class="fw-semibold">{{ number_format($r['call_coins']) }}</td>
          <td class="fw-semibold">{{ number_format($r['gift_coins']) }}</td>
          <td class="fw-semibold">{{ number_format($r['gross_coins']) }}</td>
          <td>{{ number_format((float) $r['host_payout_percentage'], 2) }}%</td>
          @if($range==='weekly')
            <td>{{ number_format($r['host_weekly_bonus']) }}</td>
          @endif
          <td class="fw-semibold">{{ number_format($r['host_payable']) }}</td>
          <td>{{ number_format((float) $r['agency_payout_percentage'], 2) }}%</td>
          <td>{{ number_format($r['agency_payable']) }}</td>
          <td>{{ $r['gift_events'] }}</td>
        </tr>
      @empty
        <tr><td colspan="{{ $range==='weekly' ? 15 : 14 }}" class="text-center text-muted py-4">No data in this range.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
