@extends('layouts.admin-berry')

@section('content')
@php
  $settings = $payload['settings'] ?? [];
  $round = $payload['current_round'] ?? null;
  $recentRounds = $payload['recent_rounds'] ?? collect();
  $recentBets = $payload['recent_bets'] ?? collect();
  $recentPayouts = $payload['recent_payouts'] ?? collect();
  $companySummary = $payload['company_summary'] ?? [];
  $financialAccount = $payload['financial_account'] ?? [];
  $financialLedger = $payload['recent_financial_ledger_entries'] ?? collect();
  $multipliers = data_get($settings, 'pot_multipliers', []);
  $weights = data_get($settings, 'outcome_weights', []);
@endphp
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
      <h3 class="mb-1">Lucky 7</h3>
      <p class="text-muted mb-0">Backend-controlled two-dice game: totals 2–6 win Down, total 7 wins Exact 7, and totals 8–12 win Up.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('admin.settings.games.edit', ['game' => 'seven_up_down']) }}" class="btn btn-light border">Game Settings</a>
      <a href="{{ route('admin.games.seven-up-down.report') }}" class="btn btn-light border">User Report</a>
      <a href="{{ route('admin.games.seven-up-down.rounds') }}" class="btn btn-light border">Rounds</a>
      <a href="{{ route('admin.games.seven-up-down.bets') }}" class="btn btn-light border">Bets</a>
      <a href="{{ route('admin.games.seven-up-down.payouts') }}" class="btn btn-light border">Payouts</a>
      <form method="post" action="{{ route('admin.games.seven-up-down.tick') }}">
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

  <div class="alert alert-light border shadow-sm mb-4" role="note">
    <div class="fw-semibold mb-1">Settlement rules</div>
    <div class="small text-muted">Multipliers are total return values including the winning stake. Each round snapshots its multipliers and persists both dice, the total, and the winning pot before broadcasting the result. Wallet debits, payouts, refunds, and financial allocations use idempotent references and remain traceable in the ledgers below.</div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Treasury Balance</div><div class="fs-4 fw-semibold">{{ number_format((int) data_get($financialAccount, 'treasury_balance_coins', 0)) }}</div></div></div></div>
    <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Company Commission</div><div class="fs-4 fw-semibold">{{ number_format((int) data_get($financialAccount, 'company_commission_balance_coins', 0)) }}</div></div></div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Company Bet Volume</div><div class="fs-4 fw-semibold">{{ number_format((int) data_get($companySummary, 'total_bet_amount', 0)) }}</div><div class="small text-muted mt-1">{{ data_get($companySummary, 'label', 'Last 30 days') }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Win Amount Given</div><div class="fs-4 fw-semibold">{{ number_format((int) data_get($companySummary, 'total_win_amount', 0)) }}</div><div class="small text-muted mt-1">Payouts credited to users</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Refunded</div><div class="fs-4 fw-semibold">{{ number_format((int) data_get($companySummary, 'refunded_amount', 0)) }}</div><div class="small text-muted mt-1">Refunded bets in same window</div></div></div></div>
    @php $companyProfit = (int) data_get($companySummary, 'profit_amount', 0); @endphp
    <div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Company Profit</div><div class="fs-4 fw-semibold {{ $companyProfit >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($companyProfit) }}</div><div class="small text-muted mt-1">Bet volume - payouts - refunds</div></div></div></div>
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
              <div class="col-md-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">Winning Pot</div><div class="fw-semibold">{{ $round['winning_pot'] ?? '—' }}</div><div class="small text-muted mt-1">Dice: {{ $round['dice_one'] ?? '—' }} + {{ $round['dice_two'] ?? '—' }} = {{ $round['dice_total'] ?? '—' }}</div></div></div>
            </div>
            <div class="row g-3 mb-3">
              @foreach(['DOWN','SEVEN','UP'] as $pot)
                <div class="col-md-4">
                  <div class="border rounded-3 p-3 h-100">
                    <div class="text-muted small">{{ $pot === 'SEVEN' ? 'Exact 7' : '7 ' . ucfirst(strtolower($pot)) }}</div>
                    <div class="fw-semibold">{{ number_format(data_get($round, "totals.$pot", 0)) }}</div>
                    <div class="small text-muted mt-1">{{ data_get($multipliers, $pot, 0) }}x | weight {{ data_get($weights, $pot, 0) }}</div>
                  </div>
                </div>
              @endforeach
            </div>
            <div class="d-flex gap-2">
              <form method="post" action="{{ route('admin.games.seven-up-down.tick') }}">
                @csrf
                <input type="hidden" name="round_id" value="{{ $round['id'] }}">
                <button class="btn btn-outline-primary btn-sm">Tick This Round</button>
              </form>
              <form method="post" action="{{ route('admin.games.seven-up-down.rounds.reconcile', $round['id']) }}">
                @csrf
                <button class="btn btn-outline-dark btn-sm">Reconcile This Round</button>
              </form>
            </div>
          @else
            <div class="text-muted">Lucky 7 is disabled or no round is available yet.</div>
          @endif
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h5 class="mb-3">Dice Outcome Configuration</h5>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Pot</th><th>Multiplier</th><th>Weight</th></tr></thead>
              <tbody>
                @foreach(['DOWN','SEVEN','UP'] as $pot)
                  <tr>
                    <td class="fw-semibold">{{ $pot === 'SEVEN' ? 'Exact 7' : '7 ' . ucfirst(strtolower($pot)) }}</td>
                    <td>{{ data_get($multipliers, $pot, 0) }}x</td>
                    <td>{{ data_get($weights, $pot, 0) }}</td>
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
            <a href="{{ route('admin.games.seven-up-down.rounds') }}" class="btn btn-sm btn-light border">Open Ledger</a>
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
            <a href="{{ route('admin.games.seven-up-down.payouts') }}" class="btn btn-sm btn-light border">Payouts</a>
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

  <div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
      <h5 class="mb-3">Financial Audit Ledger</h5>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Event</th><th>Round</th><th>Bet</th><th>Treasury Delta</th><th>Commission Delta</th><th>Occurred</th></tr></thead>
          <tbody>
            @forelse($financialLedger as $entry)
              <tr>
                <td><code>{{ $entry->event_key }}</code><div class="small text-muted">{{ $entry->event_type }}</div></td>
                <td>{{ $entry->round?->round_key ?? '—' }}</td>
                <td>{{ $entry->seven_up_down_bet_id ? '#'.$entry->seven_up_down_bet_id : '—' }}</td>
                <td>{{ number_format((int) $entry->treasury_delta_coins) }}</td>
                <td>{{ number_format((int) $entry->commission_delta_coins) }}</td>
                <td>{{ optional($entry->occurred_at)->toDateTimeString() }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted py-4">No financial events yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection
