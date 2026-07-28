@extends('layouts.admin-berry')
@section('title', 'Transaction Ledger')

@php
  $creditCoins = (int) ($summary->credit_coins ?? 0);
  $debitCoins = (int) ($summary->debit_coins ?? 0);
  $moneyLabel = $moneyTotals->isEmpty()
      ? 'No cash-linked rows'
      : $moneyTotals->map(fn ($row) => $row->currency_code.' '.number_format((float) $row->amount_total, 2))->join(' · ');
@endphp

@section('content')
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h5 class="mb-1">Platform Transaction Ledger</h5>
          <p class="text-muted mb-0">Every user-wallet credit and debit across recharges, gifts, calls, games, subscriptions, entry packs, agency transfers, and admin adjustments.</p>
        </div>
        <div class="d-flex gap-2">
          <a href="{{ route('admin.wallets.index') }}" class="btn btn-light border">
            <i class="ti ti-wallet me-1"></i>Wallets
          </a>
          <a href="{{ route('admin.wallet-transactions.export', request()->query()) }}" class="btn btn-primary">
            <i class="ti ti-download me-1"></i>Export CSV
          </a>
        </div>
      </div>
      <div class="card-body">
        <form method="get" action="{{ route('admin.wallet-transactions.index') }}" class="row g-3 align-items-end">
          <div class="col-lg-4 col-md-6">
            <label class="form-label">Search</label>
            <input name="q" value="{{ request('q') }}" class="form-control" placeholder="User, email, ledger ID, reference, gateway transaction">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
              <option value="">Credit and debit</option>
              <option value="credit" @selected(request('type') === 'credit')>Credit</option>
              <option value="debit" @selected(request('type') === 'debit')>Debit</option>
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
              <option value="">All categories</option>
              @foreach($options['categories'] as $category)
                <option value="{{ $category }}" @selected(request('category') === $category)>{{ str($category)->replace('_', ' ')->title() }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Integrity</label>
            <select name="integrity" class="form-select">
              <option value="">Any state</option>
              <option value="balanced" @selected(request('integrity') === 'balanced')>Balanced</option>
              <option value="mismatch" @selected(request('integrity') === 'mismatch')>Mismatch</option>
              <option value="missing" @selected(request('integrity') === 'missing')>Missing snapshot</option>
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Rows per page</label>
            <select name="per_page" class="form-select">
              @foreach([25, 50, 100] as $size)
                <option value="{{ $size }}" @selected((int) request('per_page', 25) === $size)>{{ $size }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="form-control">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="form-control">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">User ID</label>
            <input type="number" min="1" name="user_id" value="{{ request('user_id') }}" class="form-control" placeholder="Any user">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Wallet ID</label>
            <input type="number" min="1" name="wallet_id" value="{{ request('wallet_id') }}" class="form-control" placeholder="Any wallet">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Counterparty ID</label>
            <input type="number" min="1" name="counterparty_user_id" value="{{ request('counterparty_user_id') }}" class="form-control" placeholder="Any user">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Gateway</label>
            <select name="gateway" class="form-select">
              <option value="">All gateways</option>
              @foreach($options['gateways'] as $gateway)
                <option value="{{ $gateway }}" @selected(request('gateway') === $gateway)>{{ ucfirst($gateway) }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Reference type</label>
            <select name="reference_type" class="form-select">
              <option value="">All types</option>
              @foreach($options['reference_types'] as $referenceType)
                <option value="{{ $referenceType }}" @selected(request('reference_type') === $referenceType)>{{ $referenceType }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Minimum coins</label>
            <input type="number" min="0" name="min_coins" value="{{ request('min_coins') }}" class="form-control" placeholder="0">
          </div>
          <div class="col-lg-2 col-md-3">
            <label class="form-label">Maximum coins</label>
            <input type="number" min="0" name="max_coins" value="{{ request('max_coins') }}" class="form-control" placeholder="No maximum">
          </div>
          <div class="col-lg-4 col-md-6 d-flex gap-2">
            <button class="btn btn-primary"><i class="ti ti-filter me-1"></i>Apply filters</button>
            <a href="{{ route('admin.wallet-transactions.index') }}" class="btn btn-light border">Reset</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl col-md-6">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Transactions</div>
      <div class="fs-3 fw-semibold">{{ number_format((int) ($summary->transaction_count ?? 0)) }}</div>
      <div class="small text-muted">{{ number_format((int) ($summary->wallet_count ?? 0)) }} wallets</div>
    </div></div>
  </div>
  <div class="col-xl col-md-6">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Credits</div>
      <div class="fs-3 fw-semibold text-success">+{{ number_format($creditCoins) }}</div>
      <div class="small text-muted">Coins added</div>
    </div></div>
  </div>
  <div class="col-xl col-md-6">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Debits</div>
      <div class="fs-3 fw-semibold text-warning">-{{ number_format($debitCoins) }}</div>
      <div class="small text-muted">Coins spent</div>
    </div></div>
  </div>
  <div class="col-xl col-md-6">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Net Ledger Flow</div>
      <div class="fs-3 fw-semibold">{{ number_format($creditCoins - $debitCoins) }}</div>
      <div class="small text-muted">Credits minus debits</div>
    </div></div>
  </div>
  <div class="col-xl col-md-6">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Integrity Issues</div>
      <div class="fs-3 fw-semibold {{ (int) ($summary->anomaly_count ?? 0) > 0 ? 'text-danger' : 'text-success' }}">{{ number_format((int) ($summary->anomaly_count ?? 0)) }}</div>
      <div class="small text-muted">{{ $moneyLabel }}</div>
    </div></div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-1">Ledger Entries</h6>
        <div class="text-muted small">Showing {{ $transactions->firstItem() ?? 0 }}–{{ $transactions->lastItem() ?? 0 }} of {{ number_format($transactions->total()) }} matching entries. Times use {{ config('app.timezone') }}.</div>
      </div>
      <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0" style="min-width: 1320px">
          <thead class="table-light">
            <tr>
              <th class="ps-4">When / ID</th>
              <th>User / Wallet</th>
              <th>Type</th>
              <th>Category</th>
              <th>Coins</th>
              <th>Money</th>
              <th>Balance Movement</th>
              <th>Reference</th>
              <th>Integrity</th>
              <th class="pe-4"></th>
            </tr>
          </thead>
          <tbody>
            @forelse($transactions as $transaction)
              @php
                $expected = $transaction->balance_before === null
                    ? null
                    : ($transaction->type === 'credit' ? (int) $transaction->balance_before + (int) $transaction->coins : (int) $transaction->balance_before - (int) $transaction->coins);
                $integrityStatus = $transaction->balance_before === null || $transaction->balance_after === null
                    ? 'missing'
                    : ($expected === (int) $transaction->balance_after ? 'balanced' : 'mismatch');
                $user = $transaction->wallet?->user;
                $integrityClass = match($integrityStatus) {'balanced' => 'bg-success', 'mismatch' => 'bg-danger', default => 'bg-warning text-dark'};
              @endphp
              <tr>
                <td class="ps-4 text-nowrap">
                  <div class="fw-medium">{{ $transaction->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i:s A') }}</div>
                  <div class="text-muted small">Ledger #{{ $transaction->id }}</div>
                </td>
                <td>
                  <div class="fw-semibold">{{ $user?->name ?? 'Deleted user' }}</div>
                  <div class="text-muted small">{{ $user?->email ?? 'User unavailable' }}</div>
                  <div class="text-muted small">User #{{ $user?->id ?? '—' }} · Wallet #{{ $transaction->wallet_id }}</div>
                </td>
                <td><span class="badge {{ $transaction->type === 'credit' ? 'bg-success' : 'bg-warning text-dark' }}">{{ ucfirst($transaction->type) }}</span></td>
                <td>{{ str($transaction->category ?: 'uncategorized')->replace('_', ' ')->title() }}</td>
                <td class="fw-semibold text-nowrap {{ $transaction->type === 'credit' ? 'text-success' : 'text-warning' }}">
                  {{ $transaction->type === 'credit' ? '+' : '-' }}{{ number_format((int) $transaction->coins) }}
                </td>
                <td class="text-nowrap">{{ $transaction->amount !== null ? (($transaction->currency ?: '—').' '.number_format((float) $transaction->amount, 2)) : '—' }}</td>
                <td class="text-nowrap">
                  {{ $transaction->balance_before === null ? '—' : number_format((int) $transaction->balance_before) }}
                  <i class="ti ti-arrow-right mx-1 text-muted"></i>
                  {{ $transaction->balance_after === null ? '—' : number_format((int) $transaction->balance_after) }}
                </td>
                <td style="max-width: 300px">
                  <div class="text-break">{{ $transaction->reference ?: '—' }}</div>
                  @if($transaction->reference_type || $transaction->reference_id)
                    <div class="text-muted small text-break">{{ $transaction->reference_type ?: 'reference' }} #{{ $transaction->reference_id ?: '—' }}</div>
                  @endif
                </td>
                <td><span class="badge {{ $integrityClass }}">{{ ucfirst($integrityStatus) }}</span></td>
                <td class="pe-4"><a href="{{ route('admin.wallet-transactions.show', $transaction) }}" class="btn btn-sm btn-light border">Audit</a></td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-5">No wallet transactions match the selected filters.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer">{{ $transactions->links() }}</div>
    </div>
  </div>
</div>
@endsection
