@extends('layouts.admin-berry')

@section('title', 'Teen Patti Bets')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-coins"></i> Bet Ledger</span>
          <h1 class="admin-page-title">Teen Patti Bets</h1>
          <p class="admin-page-subtitle">Audit player bets, round exposure, and run targeted refunds for unresolved entries.</p>
        </div>
      </div>
    </section>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <h5 class="mb-0">Bet Ledger</h5>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input class="form-control" name="q" value="{{ request('q') }}" placeholder="Bet id, user, email, round key">
          <select class="form-select" name="pot">
            <option value="">Any pot</option>
            @foreach(['A', 'B', 'C'] as $pot)
              <option value="{{ $pot }}" @selected(request('pot') === $pot)>{{ $pot }}</option>
            @endforeach
          </select>
          <select class="form-select" name="status">
            <option value="">Any status</option>
            @foreach(['placed', 'won', 'lost', 'refunded'] as $status)
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
              <th>Pot</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Payout</th>
              <th>Wallet Tx</th>
              <th>Placed</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse($bets as $bet)
              <tr>
                <td>{{ $bet->id }}</td>
                <td>
                  <div class="fw-semibold">{{ $bet->user?->name ?? 'Unknown' }}</div>
                  <div class="small text-muted">#{{ $bet->user_id }} · {{ $bet->user?->email }}</div>
                </td>
                <td>{{ $bet->round?->round_key ?? '—' }}</td>
                <td><span class="badge bg-light text-dark border">{{ $bet->pot }}</span></td>
                <td>{{ $bet->amount }}</td>
                <td>{{ ucfirst($bet->status) }}</td>
                <td>{{ $bet->payout_coins }}</td>
                <td>{{ $bet->wallet_transaction_id ?? '—' }}</td>
                <td>{{ optional($bet->placed_at)->format('d M H:i:s') ?? '—' }}</td>
                <td class="text-end">
                  @if(!$bet->refunded_at && !$bet->payout)
                    <form method="post" action="{{ route('admin.games.teen-patti.bets.refund', $bet) }}" class="d-inline">
                      @csrf
                      <input type="hidden" name="note" value="Admin refund from Teen Patti ledger">
                      <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Refund this bet and return the coins?')">Refund</button>
                    </form>
                  @else
                    <span class="text-muted small">—</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-5">No bets found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-end">
        {{ $bets->links() }}
      </div>
    </div>
  </div>
@endsection
