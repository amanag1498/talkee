@extends('layouts.admin-berry')

@section('title', 'Teen Patti Payouts')

@section('content')
  @php
    $payoutCollection = $payouts->getCollection();
    $creditedCount = $payoutCollection->where('status', 'credited')->count();
    $creditedCoins = (int) $payoutCollection->sum('payout_coins');
    $uniqueUsers = $payoutCollection->pluck('user_id')->filter()->unique()->count();
  @endphp

  <div class="admin-page-shell teen-patti-admin">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-receipt-2"></i> Payout Ledger</span>
          <h1 class="admin-page-title">Teen Patti Payouts</h1>
          <p class="admin-page-subtitle">
            Confirm who got paid, from which round, and which wallet transaction credited the winning amount.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="admin-page-actions">
            <a href="{{ route('admin.games.teen-patti') }}" class="btn btn-light border">Back to Dashboard</a>
          </div>
        </div>
      </div>
    </section>

    <div class="row g-3">
      <div class="col-md-4">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Credited Rows</span><div class="tp-stat-value">{{ $creditedCount }}</div></div></div>
      </div>
      <div class="col-md-4">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Coins Credited</span><div class="tp-stat-value">{{ number_format($creditedCoins) }}</div></div></div>
      </div>
      <div class="col-md-4">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Winning Users</span><div class="tp-stat-value">{{ $uniqueUsers }}</div></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
          <h5 class="mb-1">Find Payouts</h5>
          <div class="small text-muted">Search by payout ID, user, email, or round key.</div>
        </div>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input class="form-control" name="q" value="{{ request('q') }}" placeholder="Payout id, user, email, round key">
          <select class="form-select" name="status">
            <option value="">Any status</option>
            @foreach(['credited'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
          <button class="btn btn-light border">Apply</button>
        </form>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Payout</th>
              <th>User</th>
              <th>Round</th>
              <th>Source Bet</th>
              <th>Coins</th>
              <th>Status</th>
              <th>Wallet Tx</th>
              <th>Settled</th>
            </tr>
          </thead>
          <tbody>
            @forelse($payouts as $payout)
              <tr>
                <td>
                  <div class="fw-semibold">#{{ $payout->id }}</div>
                  <div class="small text-muted">Bet #{{ $payout->teen_patti_bet_id }}</div>
                </td>
                <td>
                  <div class="fw-semibold">{{ $payout->user?->name ?? 'Unknown' }}</div>
                  <div class="small text-muted">#{{ $payout->user_id }} · {{ $payout->user?->email }}</div>
                </td>
                <td>{{ $payout->round?->round_key ?? '—' }}</td>
                <td>#{{ $payout->teen_patti_bet_id }}</td>
                <td>{{ $payout->payout_coins }}</td>
                <td><span class="badge text-bg-success">{{ ucfirst($payout->status) }}</span></td>
                <td>{{ $payout->wallet_transaction_id ?? '—' }}</td>
                <td>{{ optional($payout->settled_at)->format('d M H:i:s') ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted py-5">No payouts found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-end">
        {{ $payouts->links() }}
      </div>
    </div>
  </div>

  <style>
    .teen-patti-admin .tp-stat-card {
      border-radius: 18px;
    }

    .teen-patti-admin .tp-stat-label {
      display: block;
      color: var(--admin-muted);
      font-size: .8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: .45rem;
    }

    .teen-patti-admin .tp-stat-value {
      font-size: 1.7rem;
      font-weight: 800;
      line-height: 1.1;
    }
  </style>
@endsection
