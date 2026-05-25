@extends('layouts.admin-berry')

@section('title', 'Teen Patti Payouts')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-receipt-2"></i> Payout Ledger</span>
          <h1 class="admin-page-title">Teen Patti Payouts</h1>
          <p class="admin-page-subtitle">Track credited winnings and their wallet ledger references for reconciliation.</p>
        </div>
      </div>
    </section>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <h5 class="mb-0">Payout Ledger</h5>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input class="form-control" name="q" value="{{ request('q') }}" placeholder="Payout id, user, email, round key">
          <select class="form-select" name="status">
            <option value="">Any status</option>
            @foreach(['credited'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
          <button class="btn btn-light border">Filter</button>
        </form>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Round</th>
              <th>Bet</th>
              <th>Payout Coins</th>
              <th>Status</th>
              <th>Wallet Tx</th>
              <th>Settled</th>
            </tr>
          </thead>
          <tbody>
            @forelse($payouts as $payout)
              <tr>
                <td>{{ $payout->id }}</td>
                <td>
                  <div class="fw-semibold">{{ $payout->user?->name ?? 'Unknown' }}</div>
                  <div class="small text-muted">#{{ $payout->user_id }} · {{ $payout->user?->email }}</div>
                </td>
                <td>{{ $payout->round?->round_key ?? '—' }}</td>
                <td>#{{ $payout->teen_patti_bet_id }}</td>
                <td>{{ $payout->payout_coins }}</td>
                <td>{{ ucfirst($payout->status) }}</td>
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
@endsection
