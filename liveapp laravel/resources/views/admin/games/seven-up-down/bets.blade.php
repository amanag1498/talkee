@extends('layouts.admin-berry')

@section('content')
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-1">Lucky 7 Bets</h3>
      <p class="text-muted mb-0">Accepted bets with snapshotted total-return multipliers, wallet debits, pot choice, and refund actions.</p>
    </div>
    <a href="{{ route('admin.games.seven-up-down.dashboard') }}" class="btn btn-light border">Back to Dashboard</a>
  </div>

  <form class="row g-3 mb-4">
    <div class="col-md-4"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Bet id, user, or round"></div>
    <div class="col-md-3"><input type="text" name="status" value="{{ request('status') }}" class="form-control" placeholder="Status"></div>
    <div class="col-md-2"><input type="text" name="pot" value="{{ request('pot') }}" class="form-control" placeholder="Pot"></div>
    <div class="col-md-2"><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
    <div class="col-md-2"><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Bet</th>
              <th>User</th>
              <th>Round</th>
              <th>Pot</th>
              <th>Amount</th>
              <th>Multiplier</th>
              <th>Status</th>
              <th>Payout</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse($bets as $bet)
              <tr>
                <td>#{{ $bet->id }}<div class="small text-muted">{{ optional($bet->placed_at)->toDateTimeString() }}</div></td>
                <td>{{ $bet->user?->name ?? 'User' }}<div class="small text-muted">#{{ $bet->user_id }}</div></td>
                <td>{{ $bet->round?->round_key ?? '—' }}</td>
                <td>{{ $bet->pot }}</td>
                <td>{{ number_format((int) $bet->amount) }}</td>
                <td>{{ (int) $bet->multiplier }}x</td>
                <td class="text-capitalize">{{ $bet->status }}</td>
                <td>{{ number_format((int) $bet->payout_coins) }}</td>
                <td class="text-end">
                  @if(!$bet->refunded_at && !$bet->payout)
                    <form method="post" action="{{ route('admin.games.seven-up-down.bets.refund', $bet) }}" class="d-inline">
                      @csrf
                      <button class="btn btn-sm btn-outline-danger">Refund</button>
                    </form>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="9" class="text-center text-muted py-5">No bets found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="mt-4">{{ $bets->links() }}</div>
</div>
@endsection
