@extends('layouts.admin-berry')
@section('title','Wallets')

@section('content')
<div class="row g-3">

  {{-- Top cards --}}
  <div class="col-xl-3 col-md-6">
    <div class="card bg-dark text-white">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <small class="text-white-50">Coin Supply</small>
          <div class="fs-3 fw-semibold">
            {{ number_format($coinSupply ?? 0) }}
          </div>
        </div>
        <div class="avtar avtar-lg"><i class="ti ti-coins text-white"></i></div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card">
      <div class="card-body">
        <small class="text-muted">Total Credits</small>
        <div class="fs-3 fw-semibold">{{ number_format($walletSummary['total_credits'] ?? 0) }}</div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card">
      <div class="card-body">
        <small class="text-muted">Total Debits</small>
        <div class="fs-3 fw-semibold">{{ number_format($walletSummary['total_debits'] ?? 0) }}</div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="card">
      <div class="card-body">
        <small class="text-muted">Recharge Conversion</small>
        <div class="fs-3 fw-semibold">{{ number_format($walletSummary['recharge_conversion'] ?? 0, 1) }}%</div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <form method="get" action="{{ route('admin.wallets.index') }}" class="row g-2 align-items-end">
          <div class="col-sm-4">
            <label class="form-label">Search (name/email)</label>
            <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="e.g. john or john@site.com">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Has balance</label>
            <select name="has_balance" class="form-select">
              <option value="">Any</option>
              <option value="1" @selected(request('has_balance')=='1')>Yes (&gt; 0)</option>
              <option value="0" @selected(request('has_balance')==='0')>Zero only</option>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Blocked</label>
            <select name="blocked" class="form-select">
              <option value="">Any</option>
              <option value="1" @selected(request('blocked')=='1')>Only blocked</option>
              <option value="0" @selected(request('blocked')==='0')>Only active</option>
            </select>
          </div>
          <div class="col-sm-2 d-grid">
            <button class="btn btn-primary"><i class="ti ti-search me-1"></i>Filter</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-shield-search me-2"></i>Billing Reconciliation</h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6 col-xl-2">
            <div class="border rounded-4 p-3 h-100">
              <div class="text-muted small">Missing Wallet Tx</div>
              <div class="fs-4 fw-bold">{{ $reconciliation['calls_missing_wallet_transaction'] ?? 0 }}</div>
            </div>
          </div>
          <div class="col-md-6 col-xl-2">
            <div class="border rounded-4 p-3 h-100">
              <div class="text-muted small">Missing Ledger</div>
              <div class="fs-4 fw-bold">{{ $reconciliation['calls_missing_earning_ledger'] ?? 0 }}</div>
            </div>
          </div>
          <div class="col-md-6 col-xl-2">
            <div class="border rounded-4 p-3 h-100">
              <div class="text-muted small">Duplicate Billing</div>
              <div class="fs-4 fw-bold">{{ $reconciliation['duplicate_billing_references'] ?? 0 }}</div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="border rounded-4 p-3 h-100">
              <div class="text-muted small">Failed Calls With Billing</div>
              <div class="fs-4 fw-bold">{{ $reconciliation['failed_calls_with_billing_entries'] ?? 0 }}</div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="border rounded-4 p-3 h-100">
              <div class="text-muted small">Ended Calls Missing Billing</div>
              <div class="fs-4 fw-bold">{{ $reconciliation['completed_calls_missing_billing'] ?? 0 }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Table --}}
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="ti ti-wallet me-2"></i>Wallets</h6>
        <span class="text-muted small">Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}</span>
      </div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Email</th>
              <th>Balance (coins)</th>
              <th>Blocked</th>
              <th>Updated</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($users as $u)
              <tr>
                <td>{{ $u->id }}</td>
                <td class="fw-medium"><a href="{{ route('admin.users.show', $u) }}">{{ $u->name ?? '—' }}</a></td>
                <td class="text-muted">{{ $u->email ?? '—' }}</td>
                <td class="fw-semibold">{{ number_format($u->wallet?->balance ?? 0) }}</td>
                <td>
                  @if($u->is_blocked)
                    <span class="badge bg-danger">Blocked</span>
                  @else
                    <span class="badge bg-success">Active</span>
                  @endif
                </td>
                <td>{{ $u->wallet?->updated_at?->diffForHumans() ?? '—' }}</td>
                <td class="text-end">
                  <a href="{{ route('admin.users.show', $u) }}" class="btn btn-sm btn-light border">
                    <i class="ti ti-user-circle me-1"></i>Profile
                  </a>
                  <a href="{{ route('admin.wallets.show', $u) }}" class="btn btn-sm btn-primary">
                    <i class="ti ti-eye me-1"></i>View
                  </a>
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-4">No wallets found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        {{ $users->withQueryString()->links() }}
      </div>
    </div>
  </div>

</div>
@endsection
