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

<div class="row g-3 mb-3">
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Active</div><div class="h4 mb-0">{{ number_format($summary['active'] ?? 0) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Purchased</div><div class="h4 mb-0">{{ number_format($summary['purchased'] ?? 0) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Signup Gifts</div><div class="h4 mb-0">{{ number_format($summary['gifted'] ?? 0) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Admin Grants</div><div class="h4 mb-0">{{ number_format($summary['admin_grant'] ?? 0) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Admin Charged</div><div class="h4 mb-0">{{ number_format($summary['admin_charged'] ?? 0) }}</div></div></div></div>
  <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Cancelled</div><div class="h4 mb-0">{{ number_format($summary['cancelled'] ?? 0) }}</div></div></div></div>
</div>

<div class="card mb-3">
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
