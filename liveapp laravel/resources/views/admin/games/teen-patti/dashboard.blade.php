@extends('layouts.admin-berry')

@section('title', 'Teen Patti')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-device-gamepad-2"></i> Game Operations</span>
          <h1 class="admin-page-title">Teen Patti</h1>
          <p class="admin-page-subtitle">
            Monitor the current round, recent bets, payout credits, and whether the game is available in the video room strip.
          </p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <div class="d-flex justify-content-lg-end gap-2 flex-wrap">
            <a href="{{ route('admin.games.teen-patti.rounds') }}" class="btn btn-light border">Rounds</a>
            <a href="{{ route('admin.games.teen-patti.bets') }}" class="btn btn-light border">Bets</a>
            <a href="{{ route('admin.games.teen-patti.payouts') }}" class="btn btn-light border">Payouts</a>
            <form method="post" action="{{ route('admin.games.teen-patti.tick') }}">
              @csrf
              <button class="btn btn-primary"><i class="ti ti-player-play me-1"></i>Tick</button>
            </form>
          </div>
        </div>
      </div>
    </section>

    <div class="row g-4">
      <div class="col-xl-4">
        <div class="card">
          <div class="card-header"><h5 class="mb-0">Current State</h5></div>
          <div class="card-body">
            @php($round = $payload['current_round'])
            <dl class="row mb-0">
              <dt class="col-6">Enabled</dt>
              <dd class="col-6">{{ $payload['settings']['enabled'] ? 'Yes' : 'No' }}</dd>
              <dt class="col-6">Video Room Strip</dt>
              <dd class="col-6">{{ $payload['settings']['visible_in_video_room_strip'] ? 'Visible' : 'Hidden' }}</dd>
              <dt class="col-6">Min / Max Bet</dt>
              <dd class="col-6">{{ $payload['settings']['min_bet'] }} / {{ $payload['settings']['max_bet'] }}</dd>
              <dt class="col-6">Round Duration</dt>
              <dd class="col-6">{{ $payload['settings']['round_duration_seconds'] }}s</dd>
              <dt class="col-6">Current Round</dt>
              <dd class="col-6">{{ $round['round_key'] ?? '—' }}</dd>
              <dt class="col-6">Status</dt>
              <dd class="col-6">{{ $round['status'] ?? '—' }}</dd>
              <dt class="col-6">Winning Pot</dt>
              <dd class="col-6">{{ $round['winning_pot'] ?? '—' }}</dd>
              <dt class="col-6">Display Until</dt>
              <dd class="col-6">{{ !empty($round['display_until']) ? \Illuminate\Support\Carbon::parse($round['display_until'])->format('d M H:i:s') : '—' }}</dd>
            </dl>
            @if(!empty($round['id']))
              <div class="mt-3 d-grid">
                <form method="post" action="{{ route('admin.games.teen-patti.rounds.reconcile', $round['id']) }}">
                  @csrf
                  <button class="btn btn-outline-primary w-100">Reconcile Current Round</button>
                </form>
              </div>
            @endif
          </div>
        </div>
      </div>

      <div class="col-xl-8">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Rounds</h5>
            <a href="{{ route('admin.settings.games.edit') }}" class="btn btn-sm btn-outline-primary">Game Settings</a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Round</th>
                  <th>Status</th>
                  <th>Totals</th>
                  <th>Winning Pot</th>
                  <th>Starts</th>
                  <th>Ends</th>
                </tr>
              </thead>
              <tbody>
                @forelse($payload['recent_rounds'] as $round)
                  <tr>
                    <td>{{ $round->round_key }}</td>
                    <td>{{ $round->status }}</td>
                    <td>A: {{ $round->total_bet_a }} / B: {{ $round->total_bet_b }} / C: {{ $round->total_bet_c }}</td>
                    <td>{{ $round->winning_pot ?? '—' }}</td>
                    <td>{{ optional($round->starts_at)->format('d M H:i:s') }}</td>
                    <td>{{ optional($round->ends_at)->format('d M H:i:s') }}</td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-muted">No rounds yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-xl-6">
        <div class="card">
          <div class="card-header"><h5 class="mb-0">Recent Bets</h5></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>User</th>
                  <th>Round</th>
                  <th>Pot</th>
                  <th>Amount</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                @forelse($payload['recent_bets'] as $bet)
                  <tr>
                    <td>{{ $bet->id }}</td>
                    <td>{{ $bet->user?->name }} <small class="text-muted">#{{ $bet->user_id }}</small></td>
                    <td>{{ $bet->round?->round_key }}</td>
                    <td>{{ $bet->pot }}</td>
                    <td>{{ $bet->amount }}</td>
                    <td>{{ $bet->status }}</td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-muted">No bets yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-xl-6">
        <div class="card">
          <div class="card-header"><h5 class="mb-0">Recent Payouts</h5></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>User</th>
                  <th>Round</th>
                  <th>Payout</th>
                  <th>Status</th>
                  <th>Settled</th>
                </tr>
              </thead>
              <tbody>
                @forelse($payload['recent_payouts'] as $payout)
                  <tr>
                    <td>{{ $payout->id }}</td>
                    <td>{{ $payout->user?->name }} <small class="text-muted">#{{ $payout->user_id }}</small></td>
                    <td>{{ $payout->round?->round_key }}</td>
                    <td>{{ $payout->payout_coins }}</td>
                    <td>{{ $payout->status }}</td>
                    <td>{{ optional($payout->settled_at)->format('d M H:i:s') }}</td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-muted">No payouts yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection
