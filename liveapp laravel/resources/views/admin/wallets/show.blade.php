@extends('layouts.admin-berry')
@section('title','Wallet · '.$user->name)

@section('content')
@php
  $adminWalletCategoryOptions = [
    'recharge' => 'Recharge',
    'purchase' => 'Wallet purchase',
    'gift' => 'Gift',
    'subscription' => 'Subscription purchase',
    'entry_pack_purchase' => 'Entry pack purchase',
    'game_bet_debit' => 'Game bet / spin debit',
    'game_payout_credit' => 'Game payout credit',
    'game_refund_credit' => 'Game refund credit',
    'agency_credit' => 'Agency wallet credit',
    'audio_call' => 'Audio call',
    'video_call' => 'Video call',
    'adjustment' => 'Adjustment',
    'other' => 'Other',
  ];

  $adminWalletTxLabel = function ($tx) {
      $reference = trim((string) ($tx->reference ?? ''));
      $category = strtolower(trim((string) ($tx->category ?? '')));
      $type = strtolower(trim((string) ($tx->type ?? '')));
      $game = strtolower(trim((string) data_get($tx->meta, 'game', '')));
      $gameLabel = match ($game) {
          'teen_patti' => 'Teen Patti',
          'greedy' => 'Greedy',
          'seven_up_down' => 'Lucky 7',
          'fortune_wheel' => 'Fortune Wheel',
          default => 'Game',
      };

      if (str_starts_with($reference, 'ENTRY_PACK_PURCHASE:')) {
          return 'Entry pack purchase';
      }

      return match ($category) {
          'subscription' => 'Subscription purchase',
          'recharge', 'purchase' => 'Wallet recharge',
          'gift' => 'Gift sent',
          'game_bet_debit' => $game === 'fortune_wheel' ? 'Fortune Wheel spin debit' : $gameLabel.' bet debit',
          'game_payout_credit' => $gameLabel.' payout credit',
          'game_refund_credit' => $gameLabel.' refund credit',
          'agency_credit' => 'Agency wallet credit',
          'audio_call' => 'Audio call spend',
          'video_call' => 'Video call spend',
          'adjustment' => $type === 'credit' ? 'Wallet credit' : 'Wallet debit',
          'other' => $type === 'credit' ? 'Wallet credit' : 'Wallet spend',
          default => str_replace('_', ' ', ucfirst($category)),
      };
  };
@endphp
<div class="row g-3">

  {{-- Summary --}}
  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="avtar avtar-lg bg-light-primary"><i class="ti ti-wallet text-primary"></i></div>
          <div>
            <div class="fw-semibold fs-5">{{ $user->name }}</div>
            <div class="text-muted small">{{ $user->email }}</div>
          </div>
        </div>
        <div class="d-flex align-items-center gap-4">
          <div>
            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-light border">User Profile</a>
          </div>
          <div>
            <small class="text-muted d-block">Balance (coins)</small>
            <div class="fs-4 fw-bold">{{ number_format($wallet->balance) }}</div>
          </div>
          <div>
            <small class="text-muted d-block">Level</small>
            <div class="fs-6 fw-semibold">
              @if($user->level)
                <span class="badge" style="background: {{ $user->level->badge_color ?: '#6c757d' }}">L{{ $user->level->level }} · {{ $user->level->title }}</span>
              @else
                <span class="text-muted">Unassigned</span>
              @endif
            </div>
          </div>
          <div>
            <small class="text-muted d-block">Lifetime Spend</small>
            <div class="fs-5 fw-semibold">{{ number_format($levelProgress['lifetime_spend_coins'] ?? 0) }}</div>
          </div>
          <div>
            <small class="text-muted d-block">Transactions</small>
            <div class="fs-5 fw-semibold">{{ $transactions?->total() ?? $wallet->transactions->count() }}</div>
          </div>
          <div>
            <small class="text-muted d-block">Status</small>
            @if($user->is_blocked)
              <span class="badge bg-danger">Blocked</span>
            @else
              <span class="badge bg-success">Active</span>
            @endif
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Left: forms --}}
  <div class="col-lg-4">
    <div class="card sticky-top" style="top: 90px;">
      <div class="card-header"><h6 class="mb-0"><i class="ti ti-plus me-2"></i>Adjust / Purchase / Spend</h6></div>
      <div class="card-body">

        {{-- Purchase (money -> coins) --}}
        <form method="post" action="{{ route('admin.wallets.purchase',$user) }}" class="vstack gap-2 mb-4">
          @csrf
          <div class="fw-semibold mb-1">Record Purchase</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Coins</label>
              <input type="number" name="coins" min="1" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Amount (money)</label>
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" required>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label">Currency</label>
              <input type="text" name="currency" value="INR" maxlength="3" class="form-control text-uppercase">
            </div>
            <div class="col-8">
              <label class="form-label">Gateway</label>
              <input type="text" name="gateway" class="form-control" placeholder="razorpay/stripe…">
            </div>
          </div>
          <label class="form-label">Transaction ID</label>
          <input type="text" name="transaction_id" class="form-control" placeholder="gateway txn id">
          <input type="text" name="reference" class="form-control" placeholder="Reference (optional)">
          <textarea name="note" rows="2" class="form-control" placeholder="Note (optional)"></textarea>
          <button class="btn btn-primary w-100"><i class="ti ti-shopping-cart-plus me-1"></i>Record Purchase</button>
        </form>

        {{-- Admin Credit (coins only) --}}
        <form method="post" action="{{ route('admin.wallets.credit',$user) }}" class="vstack gap-2 mb-3">
          @csrf
          <div class="fw-semibold mb-1">Admin Credit (coins)</div>
          <input type="number" name="amount" min="1" class="form-control" placeholder="Coins" required>
          <input type="text" name="reference" class="form-control" placeholder="Reference (optional)">
          <textarea name="note" rows="2" class="form-control" placeholder="Note (optional)"></textarea>
          <button class="btn btn-success w-100"><i class="ti ti-check me-1"></i>Credit</button>
        </form>

        {{-- Admin Debit (coins only) --}}
        <form method="post" action="{{ route('admin.wallets.debit',$user) }}" class="vstack gap-2 mb-3">
          @csrf
          <div class="fw-semibold mb-1">Admin Debit (coins)</div>
          <input type="number" name="amount" min="1" class="form-control" placeholder="Coins" required>
          <input type="text" name="reference" class="form-control" placeholder="Reference (optional)">
          <textarea name="note" rows="2" class="form-control" placeholder="Note (optional)"></textarea>
          <button class="btn btn-danger w-100"><i class="ti ti-minus me-1"></i>Debit</button>
        </form>

        {{-- Simulate Spend (coins only) --}}
        <form method="post" action="{{ route('admin.wallets.spend',$user) }}" class="vstack gap-2">
          @csrf
          <div class="fw-semibold mb-1">Record Spend</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Coins</label>
              <input type="number" name="coins" min="1" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Category</label>
              <select name="category" class="form-select">
                <option value="gift">Gift</option>
                <option value="audio_call">Audio Call</option>
                <option value="video_call">Video Call</option>
                <option value="other">Other</option>
              </select>
            </div>
          </div>
          <label class="form-label">Counterparty User (Host) ID</label>
          <input type="number" name="counterparty_user_id" class="form-control" placeholder="User ID (optional)">
          <input type="text" name="reference" class="form-control" placeholder="Reference (optional)">
          <textarea name="note" rows="2" class="form-control" placeholder="Note (optional)"></textarea>
          <button class="btn btn-warning w-100"><i class="ti ti-arrow-up-right me-1"></i>Record Spend</button>
        </form>

      </div>
    </div>
  </div>

  {{-- Right: ledger --}}
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-badge me-2"></i>Level Progress</h6>
      </div>
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-6">
            <div class="fw-semibold">
              @if($user->level)
                Level {{ $user->level->level }} · {{ $user->level->title }}
              @else
                No level assigned
              @endif
            </div>
            <div class="text-muted small">
              @if(!empty($levelProgress['next_level']))
                {{ number_format($levelProgress['remaining_spend_to_next_level'] ?? 0) }} coins to Level {{ $levelProgress['next_level'] }} · {{ $levelProgress['next_level_title'] }}
              @else
                User is at the highest active level.
              @endif
            </div>
          </div>
          <div class="col-md-6">
            <div class="progress" style="height: 10px;">
              <div class="progress-bar" role="progressbar" style="width: {{ (float) ($levelProgress['progress_percent'] ?? 0) }}%"></div>
            </div>
            <div class="small text-muted mt-2">
              {{ number_format($levelProgress['lifetime_spend_coins'] ?? 0) }}
              @if(!empty($levelProgress['next_level_required_spend']))
                / {{ number_format($levelProgress['next_level_required_spend']) }}
              @endif
              coins spent
            </div>
          </div>
        </div>
        @if($levelHistory->isNotEmpty())
          <hr>
          <div class="fw-semibold mb-2">Recent Level History</div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>From</th>
                  <th>To</th>
                  <th>Spend</th>
                  <th>Triggered By</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                @foreach($levelHistory as $row)
                  <tr>
                    <td>{{ $row->oldLevel?->title ? 'L'.$row->oldLevel->level.' · '.$row->oldLevel->title : '—' }}</td>
                    <td>{{ 'L'.$row->newLevel->level.' · '.$row->newLevel->title }}</td>
                    <td>{{ number_format($row->lifetime_spend_coins) }}</td>
                    <td>{{ $row->triggered_by_transaction_id ? '#'.$row->triggered_by_transaction_id : 'Recalculate' }}</td>
                    <td>{{ $row->created_at?->format('d M Y, H:i') }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-filter me-2"></i>Ledger Filters</h6>
      </div>
      <div class="card-body">
        <form method="get" class="row g-2">
          <div class="col-md-2">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
              <option value="">Any</option>
              <option value="credit" @selected(request('type') === 'credit')>Credit</option>
              <option value="debit" @selected(request('type') === 'debit')>Debit</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
              <option value="">Any</option>
              @foreach($adminWalletCategoryOptions as $category => $label)
                <option value="{{ $category }}" @selected(request('category') === $category)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Call ID</label>
            <input type="number" name="call_id" value="{{ request('call_id') }}" class="form-control" placeholder="Call ID">
          </div>
          <div class="col-md-2">
            <label class="form-label">Recharge</label>
            <select name="recharge_status" class="form-select">
              <option value="">Any</option>
              @foreach(['created','pending','success','failed','cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('recharge_status') === $status)>{{ ucfirst($status) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Date From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Date To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
          </div>
          <div class="col-md-2 d-flex align-items-end gap-2">
            <button class="btn btn-primary flex-fill">Apply</button>
            <a href="{{ route('admin.wallets.show', $user) }}" class="btn btn-light border">Clear</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-shield-search me-2"></i>Reconciliation Snapshot</h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          @foreach($reconciliation as $label => $value)
            <div class="col-md-6 col-xl-4">
              <div class="border rounded-4 p-3 h-100">
                <div class="text-muted small">{{ str_replace('_', ' ', ucfirst($label)) }}</div>
                <div class="fs-4 fw-bold">{{ $value }}</div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-list-details me-2"></i>Ledger</h6>
        <div class="text-muted small">
          @php
            $rows = isset($transactions) ? $transactions->items() : $wallet->transactions;
            $credited = collect($rows)->where('type','credit')->sum('coins');
            $debited  = collect($rows)->where('type','debit')->sum('coins');
          @endphp
          <span class="me-3">Credited: <b>{{ number_format($credited) }}</b></span>
          <span>Debited: <b>{{ number_format($debited) }}</b></span>
        </div>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Type</th>
              <th>Category</th>
              <th>Coins</th>
              <th>Amount</th>
              <th>Txn</th>
              <th>Gateway</th>
              <th>Counterparty</th>
              <th>Ref / Details</th>
              <th>Balance</th>
              <th>When</th>
            </tr>
          </thead>
          <tbody>
          @php
            $txs = $transactions ?? $wallet->transactions; // supports both paginated + loaded
          @endphp
          @forelse($txs as $tx)
            <tr>
              <td>{{ $tx->id }}</td>
              <td>
                <span class="badge {{ $tx->type==='credit'?'bg-success':'bg-danger' }}">
                  {{ ucfirst($tx->type) }}
                </span>
              </td>
              <td><span class="badge bg-light text-dark border">{{ $adminWalletTxLabel($tx) }}</span></td>
              <td class="fw-semibold">{{ number_format($tx->coins) }}</td>
              <td class="text-nowrap">
                @if(!is_null($tx->amount))
                  {{ $tx->currency ?? 'INR' }} {{ number_format($tx->amount, 2) }}
                @else
                  —
                @endif
              </td>
              <td><code>{{ $tx->transaction_id ?? '-' }}</code></td>
              <td>{{ $tx->gateway ?? '—' }}</td>
              <td>
                @if($tx->counterparty)
                  <div class="d-flex flex-column">
                    <span class="fw-medium">{{ $tx->counterparty->name }}</span>
                    <small class="text-muted">{{ $tx->counterparty->email }}</small>
                  </div>
                @else
                  —
                @endif
              </td>
              <td style="max-width: 220px;">
                <code class="d-block">{{ $tx->reference ?? '-' }}</code>
                @if($tx->reference_type && $tx->reference_id)
                  <small class="d-block text-muted">{{ $tx->reference_type }} #{{ $tx->reference_id }}</small>
                @endif
                @if($tx->description)
                  <small class="d-block text-muted">{{ $tx->description }}</small>
                @endif
                @if(data_get($tx->meta, 'agency_id'))
                  <small class="d-block text-muted">Agency #{{ data_get($tx->meta, 'agency_id') }}{{ data_get($tx->meta, 'agency_name') ? ' · '.data_get($tx->meta, 'agency_name') : '' }}</small>
                @endif
                @if(data_get($tx->meta, 'credited_by_admin_user_id'))
                  <small class="d-block text-muted">Credited by admin #{{ data_get($tx->meta, 'credited_by_admin_user_id') }}{{ data_get($tx->meta, 'credited_by_admin_name') ? ' · '.data_get($tx->meta, 'credited_by_admin_name') : '' }}</small>
                @endif
                @if(data_get($tx->meta, 'credited_by_agency_user_id'))
                  <small class="d-block text-muted">Credited by agency user #{{ data_get($tx->meta, 'credited_by_agency_user_id') }}{{ data_get($tx->meta, 'credited_by_agency_user_name') ? ' · '.data_get($tx->meta, 'credited_by_agency_user_name') : '' }}</small>
                @endif
                <small class="text-muted">{{ data_get($tx->meta,'note') }}</small>
              </td>
              <td class="text-nowrap">
                @if(!is_null($tx->balance_before) || !is_null($tx->balance_after))
                  <small class="text-muted d-block">{{ number_format((int) $tx->balance_before) }} → {{ number_format((int) $tx->balance_after) }}</small>
                @else
                  —
                @endif
              </td>
              <td>{{ $tx->created_at?->format('d M Y, H:i') }}</td>
            </tr>
          @empty
            <tr><td colspan="11" class="text-center text-muted py-4">No transactions.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
      @if(isset($transactions) && method_exists($transactions,'links'))
        <div class="card-footer">
          {{ $transactions->withQueryString()->links() }}
        </div>
      @endif
    </div>
  </div>

</div>
@endsection
