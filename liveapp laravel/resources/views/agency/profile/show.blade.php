@extends('layouts.agency-berry')
@section('title', 'Agency Profile')
@section('page_intro', 'Agency account, payout policy, and ownership details used across host and payout operations.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $overviewRoute ?? route('agency.dashboard') }}">Back to Dashboard</a>
  <a class="btn btn-primary" href="{{ $payoutReportsRoute ?? route('agency.payout-reports.index') }}">Weekly Payout Reports</a>
@endsection

@section('content')
  @php
    $summary = $profile['summary'];
    $owner = $profile['owner'];
  @endphp

  <section class="row g-3">
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Hosts</small><div class="stat-value mt-1">{{ number_format($summary['host_count']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Active Hosts</small><div class="stat-value mt-1">{{ number_format($summary['active_hosts']) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Payout %</small><div class="stat-value mt-1">{{ number_format($summary['payout_percentage'], 2) }}%</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Weekly Bonus</small><div class="stat-value mt-1">{{ number_format($summary['weekly_bonus']) }}</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Agency Details</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6"><div class="text-muted small">Name</div><div class="fw-semibold">{{ $agency->name }}</div></div>
            <div class="col-6"><div class="text-muted small">Legal Name</div><div class="fw-semibold">{{ $agency->legal_name ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Contact Email</div><div class="fw-semibold">{{ $agency->contact_email ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Contact Phone</div><div class="fw-semibold">{{ $agency->contact_phone ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Blocked</div><div class="fw-semibold">{{ $summary['blocked'] ? 'Yes' : 'No' }}</div></div>
            <div class="col-6"><div class="text-muted small">Payout Reports</div><div class="fw-semibold">{{ number_format($summary['payout_reports']) }}</div></div>
          </div>
          @if($agency->notes)
            <hr>
            <div class="text-muted small mb-1">Notes</div>
            <div>{{ $agency->notes }}</div>
          @endif
        </div>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Owner Account</h5></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-6"><div class="text-muted small">Owner</div><div class="fw-semibold">{{ $owner?->name ?? '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Email</div><div class="fw-semibold">{{ $owner?->email ?? '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Registered At</div><div class="fw-semibold">{{ optional($owner?->created_at)->format('d M Y') ?: '—' }}</div></div>
            <div class="col-6"><div class="text-muted small">Role</div><div class="fw-semibold">Agency</div></div>
          </div>
        </div>
      </div>
    </div>
  </section>
@endsection
