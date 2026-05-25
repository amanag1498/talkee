@extends('layouts.admin-berry')

@section('title', 'Teen Patti Rounds')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-clock-play"></i> Game Rounds</span>
          <h1 class="admin-page-title">Teen Patti Rounds</h1>
          <p class="admin-page-subtitle">Inspect round timing, totals, winners, and run manual reconciliation when required.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <form method="post" action="{{ route('admin.games.teen-patti.tick') }}" class="d-inline">
            @csrf
            <button class="btn btn-primary">
              <i class="ti ti-player-play me-1"></i> Tick Current Round
            </button>
          </form>
        </div>
      </div>
    </section>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <h5 class="mb-0">Round History</h5>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input class="form-control" name="q" value="{{ request('q') }}" placeholder="Round id or key">
          <select class="form-select" name="status">
            <option value="">Any status</option>
            @foreach(['open', 'locked', 'settled', 'cancelled'] as $status)
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
              <th>Round</th>
              <th>Status</th>
              <th>Totals</th>
              <th>Bets</th>
              <th>Winning Pot</th>
              <th>Starts</th>
              <th>Locks</th>
              <th>Ends</th>
              <th>Settled</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rounds as $round)
              <tr>
                <td>{{ $round->id }}</td>
                <td>
                  <div class="fw-semibold">{{ $round->round_key }}</div>
                  <div class="small text-muted">Strategy: {{ $round->winning_strategy ?? '—' }}</div>
                </td>
                <td><span class="badge bg-light text-dark border">{{ ucfirst($round->status) }}</span></td>
                <td>A: {{ $round->total_bet_a }} / B: {{ $round->total_bet_b }} / C: {{ $round->total_bet_c }}</td>
                <td>{{ $round->total_bets_count }}</td>
                <td>{{ $round->winning_pot ?? '—' }}</td>
                <td>{{ optional($round->starts_at)->format('d M H:i:s') }}</td>
                <td>{{ optional($round->locks_at)->format('d M H:i:s') }}</td>
                <td>{{ optional($round->ends_at)->format('d M H:i:s') }}</td>
                <td>{{ optional($round->settled_at)->format('d M H:i:s') ?? '—' }}</td>
                <td class="text-end">
                  <form method="post" action="{{ route('admin.games.teen-patti.rounds.reconcile', $round) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-light border">Reconcile</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="11" class="text-center text-muted py-5">No rounds found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-end">
        {{ $rounds->links() }}
      </div>
    </div>
  </div>
@endsection
