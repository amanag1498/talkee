@extends('layouts.admin-berry')

@section('content')
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-1">Greedy Payouts</h3>
      <p class="text-muted mb-0">Winning bets credited through wallet ledger for Greedy rounds.</p>
    </div>
    <a href="{{ route('admin.games.greedy.dashboard') }}" class="btn btn-light border">Back to Dashboard</a>
  </div>

  <form class="row g-3 mb-4">
    <div class="col-md-4"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Payout id, user, or round"></div>
    <div class="col-md-3"><input type="text" name="status" value="{{ request('status') }}" class="form-control" placeholder="Status"></div>
    <div class="col-md-2"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Payout</th>
              <th>User</th>
              <th>Round</th>
              <th>Bet</th>
              <th>Pot</th>
              <th>Coins</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            @forelse($payouts as $payout)
              <tr>
                <td>#{{ $payout->id }}<div class="small text-muted">{{ optional($payout->settled_at)->toDateTimeString() }}</div></td>
                <td>{{ $payout->user?->name ?? 'User' }}<div class="small text-muted">#{{ $payout->user_id }}</div></td>
                <td>{{ $payout->round?->round_key ?? '—' }}</td>
                <td>#{{ $payout->greedy_bet_id }}</td>
                <td>{{ data_get($payout->meta, 'winning_pot', '—') }}</td>
                <td>{{ number_format((int) $payout->payout_coins) }}</td>
                <td class="text-capitalize">{{ $payout->status }}</td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-5">No payouts found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="mt-4">{{ $payouts->links() }}</div>
</div>
@endsection
