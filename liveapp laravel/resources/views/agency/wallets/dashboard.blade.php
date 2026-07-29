@extends('layouts.agency-berry')
@section('title', 'Agency Wallet')
@section('page_intro', 'Use active recharge plans to credit users while keeping agency treasury deductions fully traceable.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $walletRoute ?? (request()->routeIs('admin.*') ? route('admin.agencies.wallet.show', $agency) : route('agency.wallet.show')) }}">Refresh</a>
  @if(request()->routeIs('admin.*'))
    <a class="btn btn-primary" href="{{ route('admin.reports.agency-wallets.index', ['agency_id' => $agency->id]) }}">Global Report</a>
  @endif
@endsection

@section('content')
  <section class="row g-3">
    <div class="col-md-6 col-xl-3">
      <div class="card agency-stat-card">
        <div class="card-body">
          <small class="text-muted">Treasury Balance</small>
          <div class="stat-value mt-1">{{ number_format($walletSummary['balance'] ?? 0) }}</div>
          <div class="stat-meta mt-2">Current agency coin balance</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card agency-stat-card">
        <div class="card-body">
          <small class="text-muted">Admin Loaded</small>
          <div class="stat-value mt-1">{{ number_format($walletSummary['total_loaded'] ?? 0) }}</div>
          <div class="stat-meta mt-2">{{ number_format($walletSummary['loads_recorded'] ?? 0) }} load events</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card agency-stat-card">
        <div class="card-body">
          <small class="text-muted">Distributed</small>
          <div class="stat-value mt-1">{{ number_format($walletSummary['total_distributed'] ?? 0) }}</div>
          <div class="stat-meta mt-2">Base coins deducted for user recharges</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card agency-stat-card">
        <div class="card-body">
          <small class="text-muted">User Credits</small>
          <div class="stat-value mt-1">{{ number_format($walletSummary['credits_issued'] ?? 0) }}</div>
          <div class="stat-meta mt-2">Completed transfer records</div>
        </div>
      </div>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-xl-4">
      @if($canLoadWallet ?? false)
        <div class="card mb-3">
          <div class="card-header"><h5 class="mb-0">Admin Load Agency Wallet</h5></div>
          <div class="card-body">
            <form method="post" action="{{ route('admin.agencies.wallet.load', $agency) }}" class="vstack gap-2">
              @csrf
              <label class="form-label">Coins</label>
              <input type="number" name="coins" min="1" class="form-control" required>
              <label class="form-label">Reference</label>
              <input type="text" name="reference" class="form-control" placeholder="Invoice / batch / remark">
              <label class="form-label">Note</label>
              <textarea name="note" rows="3" class="form-control" placeholder="Why this load was made"></textarea>
              <button class="btn btn-primary mt-2">Load Wallet</button>
            </form>
          </div>
        </div>
      @endif

      @if($canCreditUsers ?? false)
        <div class="card">
          <div class="card-header"><h5 class="mb-0">Recharge User From Agency Wallet</h5></div>
          <div class="card-body">
            <form method="post" action="{{ request()->routeIs('admin.*') ? route('admin.agencies.wallet.credit-user', $agency) : route('agency.wallet.credit-user') }}" class="vstack gap-2">
              @csrf
              <label class="form-label">Target User ID</label>
              <input type="number" name="target_user_id" min="1" class="form-control" required>
              <label class="form-label">Recharge Plan</label>
              <select name="recharge_plan_id" class="form-select" required>
                <option value="">Select a recharge plan</option>
                @foreach($rechargePlans ?? [] as $plan)
                  <option value="{{ $plan['id'] }}" @selected(old('recharge_plan_id') == $plan['id'])>
                    @if(request()->routeIs('admin.*'))
                      {{ $plan['title'] }} — {{ number_format($plan['coins']) }} base + {{ number_format($plan['bonus_coins']) }} plan bonus + {{ number_format($plan['agency_bonus_coins']) }} agency bonus = {{ number_format($plan['agency_total_coins']) }}
                    @else
                      {{ $plan['title'] }} — {{ number_format($plan['coins']) }} coins
                    @endif
                  </option>
                @endforeach
              </select>
              @if(empty($rechargePlans))
                <div class="text-danger small">No active recharge plans are available.</div>
              @endif
              <label class="form-label">Reference</label>
              <input type="text" name="reference" class="form-control" placeholder="Campaign / support / recharge ref">
              <label class="form-label">Note</label>
              <textarea name="note" rows="3" class="form-control" placeholder="Why this user is being credited"></textarea>
              <button @disabled(empty($rechargePlans)) class="btn btn-success mt-2">Recharge User</button>
            </form>
          </div>
        </div>
      @endif
    </div>

    <div class="col-xl-8">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Agency Wallet Ledger</h5>
          <span class="text-muted small">Separate agency treasury transactions</span>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Type</th>
                <th>Category</th>
                <th>Coins</th>
                <th>Balance</th>
                <th>Target User</th>
                <th>Actor</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              @forelse($walletTransactions as $tx)
                <tr>
                  <td>{{ $tx->id }}</td>
                  <td><span class="badge {{ $tx->type === 'credit' ? 'bg-success' : 'bg-warning text-dark' }}">{{ strtoupper($tx->type) }}</span></td>
                  <td>{{ str_replace('_', ' ', ucfirst($tx->category)) }}</td>
                  <td>{{ number_format($tx->coins) }}</td>
                  <td>{{ number_format($tx->balance_before) }} → {{ number_format($tx->balance_after) }}</td>
                  <td>
                    @if($tx->targetUser)
                      <div class="fw-semibold">{{ $tx->targetUser->name }}</div>
                      <div class="text-muted small">#{{ $tx->targetUser->id }}</div>
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td>
                    @if($tx->admin)
                      <div class="fw-semibold">{{ $tx->admin->name }}</div>
                      <div class="text-muted small">Admin</div>
                    @elseif($tx->agencyUser)
                      <div class="fw-semibold">{{ $tx->agencyUser->name }}</div>
                      <div class="text-muted small">Agency</div>
                    @else
                      <span class="text-muted">System</span>
                    @endif
                  </td>
                  <td>{{ optional($tx->created_at)->format('d M Y, h:i A') }}</td>
                </tr>
              @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No agency wallet transactions yet.</td></tr>
              @endforelse
            </tbody>
          </table>
          <div class="d-flex justify-content-end">{{ $walletTransactions->withQueryString()->links() }}</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Agency Coin Transfers</h5>
          <span class="text-muted small">Linked agency treasury and user wallet flow</span>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Direction</th>
                <th>Base Coins</th>
                @if(request()->routeIs('admin.*'))
                  <th>Bonus Coins</th>
                  <th>Agency Extra Bonus</th>
                  <th>User Received</th>
                @endif
                <th>User</th>
                <th>Actor</th>
                <th>Linked Wallet Tx</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              @forelse($walletTransfers as $transfer)
                <tr>
                  <td>{{ $transfer->id }}</td>
                  <td>{{ str_replace('_', ' ', ucfirst($transfer->direction)) }}</td>
                  <td>{{ number_format($transfer->coins) }}</td>
                  @if(request()->routeIs('admin.*'))
                    <td>{{ $transfer->direction === 'agency_to_user' ? number_format($transfer->bonus_coins) : '—' }}</td>
                    <td>{{ $transfer->direction === 'agency_to_user' ? number_format($transfer->agency_bonus_coins) : '—' }}</td>
                    <td>{{ $transfer->direction === 'agency_to_user' ? number_format($transfer->total_coins) : '—' }}</td>
                  @endif
                  <td>
                    @if($transfer->targetUser)
                      <div class="fw-semibold">{{ $transfer->targetUser->name }}</div>
                      <div class="text-muted small">#{{ $transfer->targetUser->id }}</div>
                    @else
                      <span class="text-muted">Agency treasury load</span>
                    @endif
                  </td>
                  <td>
                    @if($transfer->admin)
                      <div class="fw-semibold">{{ $transfer->admin->name }}</div>
                      <div class="text-muted small">Admin</div>
                    @elseif($transfer->agencyUser)
                      <div class="fw-semibold">{{ $transfer->agencyUser->name }}</div>
                      <div class="text-muted small">Agency</div>
                    @else
                      <span class="text-muted">—</span>
                    @endif
                  </td>
                  <td>
                    <div class="text-muted small">Agency tx #{{ $transfer->agency_wallet_transaction_id }}</div>
                    @if($transfer->user_wallet_transaction_id)
                      <div class="text-muted small">User tx #{{ $transfer->user_wallet_transaction_id }}</div>
                    @endif
                  </td>
                  <td>{{ optional($transfer->created_at)->format('d M Y, h:i A') }}</td>
                </tr>
              @empty
                <tr><td colspan="{{ request()->routeIs('admin.*') ? 10 : 7 }}" class="text-center text-muted py-4">No agency wallet transfers yet.</td></tr>
              @endforelse
            </tbody>
          </table>
          <div class="d-flex justify-content-end">{{ $walletTransfers->withQueryString()->links() }}</div>
        </div>
      </div>
    </div>
  </section>

  @if(request()->routeIs('admin.*'))
    <section class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Recent Admin Audit</h5>
        <span class="text-muted small">Admin-visible audit for treasury operations</span>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Action</th>
              <th>Admin</th>
              <th>Target User</th>
              <th>Reason</th>
              <th>Time</th>
            </tr>
          </thead>
          <tbody>
            @forelse($walletAudits as $audit)
              <tr>
                <td>{{ $audit->id }}</td>
                <td>{{ str_replace('_', ' ', ucfirst($audit->action)) }}</td>
                <td>{{ $audit->admin?->name ?? '—' }}</td>
                <td>{{ $audit->targetUser?->name ? $audit->targetUser->name.' (#'.$audit->targetUser->id.')' : '—' }}</td>
                <td>{{ $audit->reason ?: '—' }}</td>
                <td>{{ optional($audit->created_at)->format('d M Y, h:i A') }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted py-4">No audit records yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  @endif
@endsection
