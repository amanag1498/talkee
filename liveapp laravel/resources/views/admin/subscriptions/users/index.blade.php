@extends('layouts.admin-berry')
@section('title','User Subscriptions')

@section('content')
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div>   @endif

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">User Subscriptions</h4>
  <a href="{{ route('admin.user-subscriptions.create') }}" class="btn btn-primary">
    <i class="ti ti-plus me-1"></i> Create Subscription
  </a>
</div>

<div class="mb-2">
  <h5 class="mb-1">Paid sales</h5>
  <div class="text-muted small">Every subscription wallet debit is one sale. Renewals remain separate audit events.</div>
</div>
<div class="row g-3 mb-4">
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Subscriptions Sold</div><div class="h4 mb-0">{{ number_format($salesSummary['sold'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Coins Collected</div><div class="h4 mb-0">{{ number_format($salesSummary['coins'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Unique Buyers</div><div class="h4 mb-0">{{ number_format($salesSummary['buyers'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Renewals</div><div class="h4 mb-0">{{ number_format($salesSummary['renewals'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Renewal Rate</div><div class="h4 mb-0">{{ number_format($salesSummary['renewal_rate'] ?? 0, 1) }}%</div></div></div></div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-1">Subscription Sales Audit</h5>
      <div class="text-muted small">Immutable purchase history from wallet transactions. Renewing one entitlement creates another row here.</div>
    </div>
    <span class="badge bg-light text-dark border">{{ number_format($sales->total()) }} matching sales</span>
  </div>
  <div class="card-body border-bottom">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-lg-4">
        <label class="form-label">Buyer or Transaction</label>
        <input name="sale_q" value="{{ request('sale_q') }}" class="form-control" placeholder="Transaction ID, user, email, or plan">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Plan</label>
        <select name="sale_plan_id" class="form-select">
          <option value="">Any plan</option>
          @foreach($plans as $plan)
            <option value="{{ $plan->id }}" @selected((string) request('sale_plan_id') === (string) $plan->id)>{{ $plan->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Sale Type</label>
        <select name="sale_kind" class="form-select">
          <option value="">Purchase + renewal</option>
          <option value="purchase" @selected(request('sale_kind') === 'purchase')>First purchase</option>
          <option value="renewal" @selected(request('sale_kind') === 'renewal')>Renewal</option>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Sold From</label>
        <input type="date" name="sale_from" value="{{ request('sale_from') }}" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Sold To</label>
        <input type="date" name="sale_to" value="{{ request('sale_to') }}" class="form-control">
      </div>
      <div class="col-12 d-flex gap-2 mt-3">
        <button class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply Sales Filters</button>
        <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-light border">Reset</a>
      </div>
    </form>
  </div>

  @if($salesByPlan->isNotEmpty())
    <div class="card-body border-bottom">
      <div class="row g-3">
        @foreach($salesByPlan as $planSales)
          <div class="col-md-6 col-xl-3">
            <div class="border rounded p-3 h-100 bg-light">
              <div class="fw-semibold">{{ $planSales['name'] }}</div>
              <div class="d-flex gap-4 mt-2 small text-muted">
                <span><strong class="d-block text-dark">{{ number_format($planSales['sold']) }}</strong>sold</span>
                <span><strong class="d-block text-dark">{{ number_format($planSales['coins']) }}</strong>coins</span>
                <span><strong class="d-block text-dark">{{ number_format($planSales['buyers']) }}</strong>buyers</span>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif

  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead>
        <tr><th>Sale</th><th>Buyer</th><th>Plan</th><th>Type</th><th class="text-end">Coins</th><th>Wallet</th><th>Audit</th></tr>
      </thead>
      <tbody>
        @forelse($sales as $sale)
          @php
            $saleMeta = is_array($sale->meta ?? null) ? $sale->meta : [];
            $buyer = $sale->wallet?->user;
            $saleEvent = $saleMeta['event'] ?? 'SUBSCRIPTION_PURCHASE';
            $saleSource = $saleMeta['source'] ?? (str_starts_with($saleEvent, 'ADMIN_') ? 'admin' : 'app');
            $saleMetaId = 'sale-meta-' . $sale->id;
          @endphp
          <tr>
            <td>
              <div class="fw-semibold">Txn #{{ $sale->id }}</div>
              <div class="small text-muted">{{ $sale->created_at?->format('d M Y, h:i A') ?? '—' }}</div>
              @if(!empty($saleMeta['subscription_id']))<span class="badge bg-light text-dark border mt-1">Sub #{{ $saleMeta['subscription_id'] }}</span>@endif
            </td>
            <td>
              <div class="fw-semibold">{{ $buyer?->name ?? 'Unknown user' }}</div>
              <div class="small text-muted">{{ $buyer?->email ?? 'No email' }}</div>
              @if($buyer)<a class="small" href="{{ route('admin.users.show', $buyer) }}">User #{{ $buyer->id }}</a>@endif
            </td>
            <td>
              <div class="fw-semibold">{{ $saleMeta['plan_name'] ?? $sale->reference ?? 'Unknown plan' }}</div>
              <div class="small text-muted">{{ $sale->reference ?? 'No reference' }}</div>
              @if(isset($saleMeta['period_starts_at'], $saleMeta['period_ends_at']))
                <div class="small text-muted mt-1">{{ \Carbon\Carbon::parse($saleMeta['period_starts_at'])->format('d M Y') }} to {{ \Carbon\Carbon::parse($saleMeta['period_ends_at'])->format('d M Y') }}</div>
              @endif
            </td>
            <td>
              <span class="badge bg-{{ $sale->audit_kind === 'renewal' ? 'warning text-dark' : 'success' }}">{{ ucfirst($sale->audit_kind) }}</span>
              <div class="small text-muted mt-1">{{ ucfirst($saleSource) }}</div>
            </td>
            <td class="text-end"><div class="fw-bold">{{ number_format((int) $sale->coins) }}</div><div class="small text-muted">debited</div></td>
            <td><div>{{ number_format((int) ($sale->balance_before ?? 0)) }} → {{ number_format((int) ($sale->balance_after ?? 0)) }}</div><div class="small text-muted">Wallet #{{ $sale->wallet_id }}</div></td>
            <td>
              <div class="small fw-semibold">{{ $saleEvent }}</div>
              @if(!empty($saleMeta))
                <button class="btn btn-sm btn-link px-0" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $saleMetaId }}">Metadata</button>
                <div class="collapse" id="{{ $saleMetaId }}"><pre class="small bg-light border rounded p-2 mb-0" style="white-space: pre-wrap; max-width: 320px;">{{ json_encode($saleMeta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">No paid subscription sales matched these filters.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $sales->links() }}</div>
</div>

<div class="mb-2">
  <h5 class="mb-1">Current entitlement state</h5>
  <div class="text-muted small">These rows represent access windows, not the number of sales.</div>
</div>
<div class="row g-3 mb-3">
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Active Access</div><div class="h4 mb-0">{{ number_format($summary['active'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Expired Access</div><div class="h4 mb-0">{{ number_format($summary['expired'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Cancelled</div><div class="h4 mb-0">{{ number_format($summary['cancelled'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Complimentary Active</div><div class="h4 mb-0">{{ number_format($summary['complimentary_active'] ?? 0) }}</div></div></div></div>
  <div class="col-md"><div class="card h-100"><div class="card-body"><div class="text-muted small">Expiring Soon</div><div class="h4 mb-0">{{ number_format($summary['expiring_soon'] ?? 0) }}</div></div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h5 class="mb-1">Subscription Entitlements</h5>
    <div class="text-muted small">Renewals extend one access row and appear separately in the sales audit above.</div>
  </div>
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Source</label>
        <select name="origin" class="form-select">
          <option value="">All sources</option>
          <option value="purchased" @selected($origin === 'purchased')>Purchased by user</option>
          <option value="gifted" @selected($origin === 'gifted')>Signup / gifted</option>
          <option value="admin_grant" @selected($origin === 'admin_grant')>Admin grant</option>
          <option value="admin_charged" @selected($origin === 'admin_charged')>Admin charged</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All statuses</option>
          @foreach(['active','expired','cancelled'] as $statusOption)
            <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-5 d-flex gap-2">
        <button class="btn btn-primary">Apply</button>
        <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-light border">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead>
        <tr>
          <th>User</th>
          <th>Plan</th>
          <th>Status</th>
          <th>Starts</th>
          <th>Ends</th>
          <th>Last Purchase</th>
          <th>Source</th>
          <th>Trace</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse($subs as $s)
          @php
            $meta = is_array($s->meta ?? null) ? $s->meta : (is_string($s->meta ?? null) ? json_decode($s->meta, true) : []);
            $meta = is_array($meta) ? $meta : [];
            $metaId = 'sub-meta-' . $s->id;
            $charged = filter_var($meta['charged'] ?? false, FILTER_VALIDATE_BOOL);
          @endphp

          <tr>
            <td><a href="{{ route('admin.users.show', $s->user) }}">{{ $s->user->name }}</a> (#{{ $s->user->id }})</td>
            <td>{{ $s->plan->name ?? '—' }}</td>
            <td>
              <span class="badge bg-{{ $s->status==='active' ? 'success' : ($s->status==='cancelled' ? 'warning' : 'secondary') }}">
                {{ $s->status }}
              </span>
            </td>
            <td>{{ $s->starts_at?->format('Y-m-d H:i') }}</td>
            <td>{{ $s->ends_at?->format('Y-m-d H:i') }}</td>
            <td>{{ $s->last_purchased_at?->format('Y-m-d H:i') }}</td>
            <td style="min-width: 160px;">
              <span class="badge {{ $s->origin_badge_class }}">{{ $s->origin_label }}</span>
              <div class="small text-muted mt-1">{{ $charged ? 'Coins charged' : 'No wallet charge' }}</div>
            </td>
            <td style="min-width: 260px;">
              <div class="fw-semibold small">{{ $s->origin_description }}</div>
              <div class="small text-muted">
                Source: {{ $meta['source'] ?? '—' }}
                @if(!empty($meta['event'] ?? $meta['last_action'] ?? null))
                  · Event: {{ $meta['event'] ?? $meta['last_action'] }}
                @endif
              </div>
              @if(!empty($meta['previous_source'] ?? null))
                <div class="small text-muted">Previous source: {{ $meta['previous_source'] }}</div>
              @endif
              @if(!empty($meta['wallet_transaction_id'] ?? null))
                <div class="small text-muted">Wallet Tx: #{{ $meta['wallet_transaction_id'] }}</div>
              @endif
              @if(!empty($meta['note'] ?? $meta['reason'] ?? null))
                <div class="small text-muted">Note: {{ \Illuminate\Support\Str::limit($meta['note'] ?? $meta['reason'], 80) }}</div>
              @endif
              @if(!empty($meta))
                <button class="btn btn-sm btn-link px-0" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $metaId }}">
                  Raw meta
                </button>
                <div class="collapse" id="{{ $metaId }}">
                  <pre class="small bg-light border rounded p-2 mb-0" style="white-space: pre-wrap;">{{ json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
              @endif
            </td>

            <td class="text-end">
              <div class="btn-group">
                <a href="{{ route('admin.user-subscriptions.edit', $s) }}" class="btn btn-sm btn-outline-primary">
                  <i class="ti ti-edit"></i> Edit
                </a>
                <a href="{{ route('admin.users.show', $s->user) }}" class="btn btn-sm btn-outline-secondary ms-1">
                  <i class="ti ti-user-circle"></i> Profile
                </a>

                @if($s->status === 'active')
                  <form method="post" action="{{ route('admin.user-subscriptions.cancel', $s->id) }}" class="ms-1">
                    @csrf
                    <button class="btn btn-sm btn-outline-warning"
                            onclick="return confirm('Cancel this subscription now?')">
                      <i class="ti ti-ban"></i> Cancel
                    </button>
                  </form>
                @endif

                <form method="post" action="{{ route('admin.user-subscriptions.destroy', $s) }}" class="ms-1">
                  @csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger"
                          onclick="return confirm('Delete this subscription? This cannot be undone.')">
                    <i class="ti ti-trash"></i> Delete
                  </button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="9" class="text-center text-muted py-4">No subscriptions yet.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-footer">
    {{ $subs->links() }}
  </div>
</div>
@endsection
