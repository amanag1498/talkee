@extends('layouts.admin-berry')

@section('title', 'Teen Patti Rounds')

@section('content')
  @php
    $roundCollection = $rounds->getCollection();
    $openCount = $roundCollection->where('status', 'open')->count();
    $lockedCount = $roundCollection->where('status', 'locked')->count();
    $settledCount = $roundCollection->where('status', 'settled')->count();
    $betVolume = (int) $roundCollection->sum(fn ($round) => (int) $round->total_bet_a + (int) $round->total_bet_b + (int) $round->total_bet_c);
  @endphp

  <div class="admin-page-shell teen-patti-admin">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-clock-play"></i> Game Rounds</span>
          <h1 class="admin-page-title">Teen Patti Round History</h1>
          <p class="admin-page-subtitle">
            Track the round timeline, see which rounds are still active, and use reconcile only when totals or settlement look wrong.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="admin-page-actions">
            <a href="{{ route('admin.games.teen-patti.dashboard') }}" class="btn btn-light border">Back to Dashboard</a>
            <form method="post" action="{{ route('admin.games.teen-patti.tick') }}">
              @csrf
              <button class="btn btn-primary"><i class="ti ti-player-play me-1"></i> Tick Current Round</button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <div class="row g-3">
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Open</span><div class="tp-stat-value">{{ $openCount }}</div></div></div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Locked</span><div class="tp-stat-value">{{ $lockedCount }}</div></div></div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Settled</span><div class="tp-stat-value">{{ $settledCount }}</div></div></div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100"><div class="card-body"><span class="tp-stat-label">Visible Volume</span><div class="tp-stat-value">{{ number_format($betVolume) }}</div></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
          <h5 class="mb-1">Filter Rounds</h5>
          <div class="small text-muted">Search by numeric ID or round key and narrow by status.</div>
        </div>
        <form method="get" class="d-flex gap-2 flex-wrap">
          <input class="form-control" name="q" value="{{ request('q') }}" placeholder="Round id or key">
          <select class="form-select" name="status">
            <option value="">Any status</option>
            @foreach(['open', 'locked', 'settled', 'cancelled'] as $status)
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
              <th>Round</th>
              <th>Status</th>
              <th>Exposure</th>
              <th>Bets</th>
              <th>Winner</th>
              <th>Timeline</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse($rounds as $round)
              @php($roundTotal = (int) $round->total_bet_a + (int) $round->total_bet_b + (int) $round->total_bet_c)
              <tr>
                <td>
                  <div class="fw-semibold">{{ $round->round_key }}</div>
                  <div class="small text-muted">#{{ $round->id }} · {{ $round->winning_strategy ?? '—' }}</div>
                </td>
                <td>
                  <span class="badge bg-light text-dark border">{{ ucfirst($round->status) }}</span>
                </td>
                <td>
                  <div class="small text-muted mb-1">A {{ $round->total_bet_a }} · B {{ $round->total_bet_b }} · C {{ $round->total_bet_c }}</div>
                  <div class="fw-semibold">{{ number_format($roundTotal) }} coins</div>
                </td>
                <td>{{ $round->total_bets_count }}</td>
                <td>{{ $round->winning_pot ? "Pot {$round->winning_pot}" : '—' }}</td>
                <td>
                  <div>{{ optional($round->starts_at)->format('d M H:i:s') }}</div>
                  <div class="small text-muted">Lock {{ optional($round->locks_at)->format('H:i:s') }} · End {{ optional($round->ends_at)->format('H:i:s') }}</div>
                  <div class="small text-muted">Settled {{ optional($round->settled_at)->format('H:i:s') ?? '—' }}</div>
                </td>
                <td class="text-end">
                  <form method="post" action="{{ route('admin.games.teen-patti.rounds.reconcile', $round) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-light border">Reconcile</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-5">No rounds found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex justify-content-end">
        {{ $rounds->links() }}
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
