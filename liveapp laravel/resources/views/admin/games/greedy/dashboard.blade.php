@extends('layouts.admin-berry')

@section('content')
@php
  $settings = $payload['settings'] ?? [];
  $round = $payload['current_round'] ?? null;
  $recentRounds = $payload['recent_rounds'] ?? collect();
  $recentBets = $payload['recent_bets'] ?? collect();
  $recentPayouts = $payload['recent_payouts'] ?? collect();
  $multipliers = data_get($settings, 'pot_multipliers', []);
  $sectors = data_get($settings, 'pot_sectors', []);
@endphp
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
      <h3 class="mb-1">Greedy</h3>
      <p class="text-muted mb-0">Realtime spinner game with 4 weighted pots and admin-controlled multipliers.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('admin.settings.games.edit', ['game' => 'greedy']) }}" class="btn btn-light border">Game Settings</a>
      <a href="{{ route('admin.games.greedy.report') }}" class="btn btn-light border">User Report</a>
      <a href="{{ route('admin.games.greedy.rounds') }}" class="btn btn-light border">Rounds</a>
      <a href="{{ route('admin.games.greedy.bets') }}" class="btn btn-light border">Bets</a>
      <a href="{{ route('admin.games.greedy.payouts') }}" class="btn btn-light border">Payouts</a>
      <form method="post" action="{{ route('admin.games.greedy.tick') }}">
        @csrf
        <button class="btn btn-primary">Tick Round</button>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Enabled</div><div class="fs-4 fw-semibold">{{ data_get($settings, 'enabled') ? 'Yes' : 'No' }}</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Visible In Room</div><div class="fs-4 fw-semibold">{{ data_get($settings, 'visible_in_video_room_strip') ? 'Yes' : 'No' }}</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Fake Bets</div><div class="fs-4 fw-semibold">{{ data_get($settings, 'fake_bets_enabled') ? 'On' : 'Off' }}</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Current Strategy</div><div class="fs-4 fw-semibold text-capitalize">{{ str_replace('_', ' ', data_get($settings, 'winning_strategy_mode', 'probability')) }}</div></div></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Live Round</h5>
            @if($round)
              <span class="badge text-bg-dark">{{ $round['round_key'] }}</span>
            @endif
          </div>
          @if($round)
            <div class="row g-3 mb-3">
              <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Status</div><div class="fw-semibold text-capitalize">{{ $round['status'] }}</div><div class="small text-muted mt-1">Phase: {{ $round['phase'] }}</div></div></div>
              <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Countdown</div><div class="fw-semibold">{{ $round['countdown_seconds'] ?? 0 }}s</div><div class="small text-muted mt-1">Locks: {{ $round['locks_at'] ?? '—' }}</div></div></div>
              <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Winning Pot</div><div class="fw-semibold">{{ $round['winning_pot'] ?? '—' }}</div><div class="small text-muted mt-1">Multiplier: {{ $round['winning_multiplier'] ?? '—' }}</div></div></div>
            </div>
            <div class="row g-3 mb-3">
              @foreach(['A','B','C','D'] as $pot)
                <div class="col-md-3">
                  <div class="border rounded-3 p-3 h-100">
                    <div class="text-muted small">Pot {{ $pot }}</div>
                    <div class="fw-semibold">{{ number_format(data_get($round, "totals.$pot", 0)) }}</div>
                    <div class="small text-muted mt-1">{{ data_get($multipliers, $pot, 0) }}x | {{ data_get($sectors, $pot, 0) }} sectors</div>
                  </div>
                </div>
              @endforeach
            </div>
            <div class="d-flex gap-2">
              <form method="post" action="{{ route('admin.games.greedy.tick') }}">
                @csrf
                <input type="hidden" name="round_id" value="{{ $round['id'] }}">
                <button class="btn btn-outline-primary btn-sm">Tick This Round</button>
              </form>
              <form method="post" action="{{ route('admin.games.greedy.rounds.reconcile', $round['id']) }}">
                @csrf
                <button class="btn btn-outline-dark btn-sm">Reconcile This Round</button>
              </form>
            </div>
          @else
            <div class="text-muted">Greedy is disabled or no round is available yet.</div>
          @endif
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h5 class="mb-3">Wheel Configuration</h5>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Pot</th><th>Multiplier</th><th>Sectors</th></tr></thead>
              <tbody>
                @foreach(['A','B','C','D'] as $pot)
                  <tr>
                    <td class="fw-semibold">Pot {{ $pot }}</td>
                    <td>{{ data_get($multipliers, $pot, 0) }}x</td>
                    <td>{{ data_get($sectors, $pot, 0) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Recent Rounds</h5>
            <a href="{{ route('admin.games.greedy.rounds') }}" class="btn btn-sm btn-light border">Open Ledger</a>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Round</th><th>Status</th><th>Winner</th><th>Total Bets</th></tr></thead>
              <tbody>
                @forelse($recentRounds as $item)
                  <tr>
                    <td>#{{ $item->id }}<div class="small text-muted">{{ $item->round_key }}</div></td>
                    <td class="text-capitalize">{{ $item->status }}</td>
                    <td>{{ $item->winning_pot ?? '—' }}</td>
                    <td>{{ number_format((int) $item->total_bets_count) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="4" class="text-muted text-center py-4">No rounds yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Recent Money Movement</h5>
            <a href="{{ route('admin.games.greedy.payouts') }}" class="btn btn-sm btn-light border">Payouts</a>
          </div>
          <div class="small text-muted mb-2">Latest bets and credited payouts.</div>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Type</th><th>User</th><th>Pot</th><th>Coins</th></tr></thead>
              <tbody>
                @foreach($recentBets->take(5) as $bet)
                  <tr>
                    <td>Bet</td>
                    <td>{{ $bet->user?->name ?? 'User' }}</td>
                    <td>{{ $bet->pot }}</td>
                    <td>{{ number_format((int) $bet->amount) }}</td>
                  </tr>
                @endforeach
                @foreach($recentPayouts->take(5) as $payout)
                  <tr>
                    <td>Payout</td>
                    <td>{{ $payout->user?->name ?? 'User' }}</td>
                    <td>{{ data_get($payout->meta, 'winning_pot', '—') }}</td>
                    <td>{{ number_format((int) $payout->payout_coins) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
