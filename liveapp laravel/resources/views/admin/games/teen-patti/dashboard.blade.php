@extends('layouts.admin-berry')

@section('title', 'Teen Patti')

@section('content')
  @php
    $round = $payload['current_round'] ?? null;
    $recentRounds = collect($payload['recent_rounds'] ?? []);
    $recentBets = collect($payload['recent_bets'] ?? []);
    $recentPayouts = collect($payload['recent_payouts'] ?? []);

    $currentTotal = (int) data_get($round, 'totals.A', 0)
      + (int) data_get($round, 'totals.B', 0)
      + (int) data_get($round, 'totals.C', 0);
    $winnerPot = data_get($round, 'winning_pot');
    $currentStatus = (string) data_get($round, 'status', 'idle');

    $settledRounds = $recentRounds->where('status', 'settled')->count();
    $openRounds = $recentRounds->whereIn('status', ['open', 'locked'])->count();
    $recentBetVolume = (int) $recentBets->sum('amount');
    $recentPayoutVolume = (int) $recentPayouts->sum('payout_coins');

    $statusTone = match ($currentStatus) {
      'open' => 'success',
      'locked' => 'warning',
      'settled' => 'primary',
      'cancelled' => 'danger',
      default => 'secondary',
    };
  @endphp

  <div class="admin-page-shell teen-patti-admin">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-device-gamepad-2"></i> Game Operations</span>
          <h1 class="admin-page-title">Teen Patti Control Room</h1>
          <p class="admin-page-subtitle">
            See whether the game is live, how much is in play, what changed recently, and jump directly to rounds, bets, payouts, or settings.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="admin-page-actions">
            <a href="{{ route('admin.settings.games.edit', ['game' => 'teen_patti']) }}" class="btn btn-light border">Game Settings</a>
            <a href="{{ route('admin.games.teen-patti.report') }}" class="btn btn-light border">User Report</a>
            <a href="{{ route('admin.games.teen-patti.rounds') }}" class="btn btn-light border">Rounds</a>
            <a href="{{ route('admin.games.teen-patti.bets') }}" class="btn btn-light border">Bets</a>
            <a href="{{ route('admin.games.teen-patti.payouts') }}" class="btn btn-light border">Payouts</a>
            <form method="post" action="{{ route('admin.games.teen-patti.tick') }}">
              @csrf
              <button class="btn btn-primary"><i class="ti ti-player-play me-1"></i> Tick Round</button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <div class="row g-3">
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100">
          <div class="card-body">
            <span class="tp-stat-label">Current Status</span>
            <div class="tp-stat-value">{{ ucfirst($currentStatus === 'idle' ? 'not started' : $currentStatus) }}</div>
            <span class="badge text-bg-{{ $statusTone }}">{{ strtoupper($currentStatus) }}</span>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100">
          <div class="card-body">
            <span class="tp-stat-label">Current Round Exposure</span>
            <div class="tp-stat-value">{{ number_format($currentTotal) }}</div>
            <div class="tp-stat-meta">Total coins shown across A, B, and C</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100">
          <div class="card-body">
            <span class="tp-stat-label">Recent Bet Volume</span>
            <div class="tp-stat-value">{{ number_format($recentBetVolume) }}</div>
            <div class="tp-stat-meta">{{ $recentBets->count() }} recent ledger rows</div>
          </div>
        </div>
      </div>
      <div class="col-md-6 col-xl-3">
        <div class="card tp-stat-card h-100">
          <div class="card-body">
            <span class="tp-stat-label">Recent Payout Volume</span>
            <div class="tp-stat-value">{{ number_format($recentPayoutVolume) }}</div>
            <div class="tp-stat-meta">{{ $recentPayouts->count() }} credited payouts</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-xl-4">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="mb-0">Live Round Snapshot</h5>
          </div>
          <div class="card-body">
            @if($round)
              <div class="tp-detail-list">
                <div class="tp-detail-row">
                  <span>Round key</span>
                  <strong>{{ $round['round_key'] ?? '—' }}</strong>
                </div>
                <div class="tp-detail-row">
                  <span>Winner</span>
                  <strong>{{ $winnerPot ? "Pot {$winnerPot}" : '—' }}</strong>
                </div>
                <div class="tp-detail-row">
                  <span>Display until</span>
                  <strong>{{ !empty($round['display_until']) ? \Illuminate\Support\Carbon::parse($round['display_until'])->format('d M H:i:s') : '—' }}</strong>
                </div>
                <div class="tp-detail-row">
                  <span>Bet count</span>
                  <strong>{{ data_get($round, 'total_bets_count', 0) }}</strong>
                </div>
                <div class="tp-detail-row">
                  <span>Participants</span>
                  <strong>{{ data_get($round, 'participant_count', 0) }}</strong>
                </div>
              </div>

              <div class="tp-pot-grid mt-3">
                <div class="tp-pot-card">
                  <span>Pot A</span>
                  <strong>{{ number_format((int) data_get($round, 'totals.A', 0)) }}</strong>
                </div>
                <div class="tp-pot-card">
                  <span>Pot B</span>
                  <strong>{{ number_format((int) data_get($round, 'totals.B', 0)) }}</strong>
                </div>
                <div class="tp-pot-card">
                  <span>Pot C</span>
                  <strong>{{ number_format((int) data_get($round, 'totals.C', 0)) }}</strong>
                </div>
              </div>

              <div class="mt-3 d-grid gap-2">
                <form method="post" action="{{ route('admin.games.teen-patti.tick') }}">
                  @csrf
                  <button class="btn btn-primary w-100">Refresh Game State</button>
                </form>
                <form method="post" action="{{ route('admin.games.teen-patti.rounds.reconcile', $round['id']) }}">
                  @csrf
                  <button class="btn btn-outline-primary w-100">Reconcile Current Round</button>
                </form>
              </div>
            @else
              <p class="text-muted mb-0">No current round payload is available yet.</p>
            @endif
          </div>
        </div>
      </div>

      <div class="col-xl-8">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="mb-0">Game Configuration Summary</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <div class="tp-summary-panel">
                  <div class="tp-summary-title">Availability</div>
                  <div class="tp-chip-row">
                    <span class="badge text-bg-{{ $payload['settings']['enabled'] ? 'success' : 'danger' }}">
                      {{ $payload['settings']['enabled'] ? 'Engine enabled' : 'Engine disabled' }}
                    </span>
                    <span class="badge text-bg-{{ $payload['settings']['visible_in_video_room_strip'] ? 'primary' : 'secondary' }}">
                      {{ $payload['settings']['visible_in_video_room_strip'] ? 'Visible in strip' : 'Hidden from strip' }}
                    </span>
                    <span class="badge text-bg-{{ $payload['settings']['fake_bets_enabled'] ? 'warning' : 'secondary' }}">
                      {{ $payload['settings']['fake_bets_enabled'] ? 'Fake bets on' : 'Fake bets off' }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="tp-summary-panel">
                  <div class="tp-summary-title">Limits and timing</div>
                  <div class="tp-summary-copy">
                    Min bet {{ $payload['settings']['min_bet'] }}, max bet {{ $payload['settings']['max_bet'] }}, round {{ $payload['settings']['round_duration_seconds'] }}s, lock {{ $payload['settings']['betting_lock_seconds'] }}s before result, display {{ $payload['settings']['result_display_seconds'] }}s.
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="tp-summary-panel">
                  <div class="tp-summary-title">Payout rule</div>
                  <div class="tp-summary-copy">
                    Winners receive {{ $payload['settings']['payout_multiplier'] }}x. Strategy mode is <strong>{{ ucfirst(str_replace('_', ' ', $payload['settings']['winning_strategy_mode'])) }}</strong>.
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="tp-summary-panel">
                  <div class="tp-summary-title">Recent health</div>
                  <div class="tp-summary-copy">
                    {{ $settledRounds }} settled rounds, {{ $openRounds }} open or locked rounds visible in the recent sample.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-xl-7">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Rounds</h5>
            <a href="{{ route('admin.games.teen-patti.rounds') }}" class="btn btn-sm btn-light border">Open full ledger</a>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead>
                <tr>
                  <th>Round</th>
                  <th>Status</th>
                  <th>Totals</th>
                  <th>Winner</th>
                  <th>Window</th>
                </tr>
              </thead>
              <tbody>
                @forelse($recentRounds->take(8) as $recentRound)
                  <tr>
                    <td>
                      <div class="fw-semibold">{{ $recentRound->round_key }}</div>
                      <div class="small text-muted">#{{ $recentRound->id }}</div>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ ucfirst($recentRound->status) }}</span></td>
                    <td>A {{ $recentRound->total_bet_a }} · B {{ $recentRound->total_bet_b }} · C {{ $recentRound->total_bet_c }}</td>
                    <td>{{ $recentRound->winning_pot ?? '—' }}</td>
                    <td>
                      <div>{{ optional($recentRound->starts_at)->format('d M H:i:s') }}</div>
                      <div class="small text-muted">to {{ optional($recentRound->ends_at)->format('H:i:s') }}</div>
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5" class="text-center text-muted py-5">No rounds yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-xl-5">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Latest Money Movement</h5>
            <div class="small text-muted">Recent bets and payouts only</div>
          </div>
          <div class="card-body">
            <div class="tp-activity-list">
              @forelse($recentBets->take(4) as $bet)
                <div class="tp-activity-item">
                  <div>
                    <div class="fw-semibold">{{ $bet->user?->name ?? 'Unknown user' }}</div>
                    <div class="small text-muted">Bet {{ $bet->amount }} on pot {{ $bet->pot }}</div>
                  </div>
                  <span class="badge bg-light text-dark border">{{ ucfirst($bet->status) }}</span>
                </div>
              @empty
                <div class="text-muted">No recent bets.</div>
              @endforelse

              @forelse($recentPayouts->take(4) as $payout)
                <div class="tp-activity-item">
                  <div>
                    <div class="fw-semibold">{{ $payout->user?->name ?? 'Unknown user' }}</div>
                    <div class="small text-muted">Payout {{ $payout->payout_coins }} from {{ $payout->round?->round_key ?? '—' }}</div>
                  </div>
                  <span class="badge text-bg-success">{{ ucfirst($payout->status) }}</span>
                </div>
              @empty
                <div class="text-muted">No recent payouts.</div>
              @endforelse
            </div>
          </div>
        </div>
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
      margin-bottom: .45rem;
    }

    .teen-patti-admin .tp-stat-meta {
      color: var(--admin-muted);
      font-size: .88rem;
    }

    .teen-patti-admin .tp-detail-list {
      display: grid;
      gap: .7rem;
    }

    .teen-patti-admin .tp-detail-row {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      padding-bottom: .7rem;
      border-bottom: 1px solid rgba(148, 163, 184, 0.14);
    }

    .teen-patti-admin .tp-detail-row:last-child {
      padding-bottom: 0;
      border-bottom: 0;
    }

    .teen-patti-admin .tp-detail-row span {
      color: var(--admin-muted);
    }

    .teen-patti-admin .tp-pot-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .75rem;
    }

    .teen-patti-admin .tp-pot-card,
    .teen-patti-admin .tp-summary-panel {
      border: 1px solid rgba(148, 163, 184, 0.16);
      border-radius: 16px;
      background: rgba(248, 250, 252, 0.72);
      padding: .9rem 1rem;
    }

    .teen-patti-admin .tp-pot-card span,
    .teen-patti-admin .tp-summary-title {
      display: block;
      color: var(--admin-muted);
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: .3rem;
    }

    .teen-patti-admin .tp-pot-card strong {
      font-size: 1.15rem;
      font-weight: 800;
    }

    .teen-patti-admin .tp-chip-row {
      display: flex;
      flex-wrap: wrap;
      gap: .45rem;
    }

    .teen-patti-admin .tp-summary-copy {
      color: var(--admin-text);
      line-height: 1.45;
      font-size: .92rem;
    }

    .teen-patti-admin .tp-activity-list {
      display: grid;
      gap: .8rem;
    }

    .teen-patti-admin .tp-activity-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      border: 1px solid rgba(148, 163, 184, 0.16);
      border-radius: 14px;
      padding: .85rem .95rem;
      background: rgba(248, 250, 252, 0.72);
    }
  </style>
@endsection
