@extends('layouts.admin-berry')
@section('title','Entry Pack Reports')

@section('content')
<div class="admin-section-stack">
  <div class="row g-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Ownership Records</div><div class="h3 mb-0">{{ number_format($report['ownerships'] ?? 0) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Paid Purchases</div><div class="h3 mb-0">{{ number_format($report['paid_purchases'] ?? 0) }}</div><div class="small text-muted">{{ number_format($report['coins_spent'] ?? 0) }} coins spent</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Wheel Grants</div><div class="h3 mb-0">{{ number_format($report['wheel_grants'] ?? 0) }}</div><div class="small text-muted">{{ number_format($report['wheel_grant_hours'] ?? 0) }} hours granted</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Purchased</div><div class="h3 mb-0">{{ number_format($report['purchased'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Gifted</div><div class="h3 mb-0">{{ number_format($report['gifted'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Admin Grants</div><div class="h3 mb-0">{{ number_format($report['admin_grant'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Admin Charged</div><div class="h3 mb-0">{{ number_format($report['admin_charged'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Expired Ownerships</div><div class="h3 mb-0">{{ number_format($report['expired_owned'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Expiry Churn</div><div class="h3 mb-0">{{ number_format($report['expiry_churn_rate'] ?? 0, 1) }}%</div></div></div></div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Most Owned Packs</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>#</th><th>Name</th><th>Ownerships</th><th>Price</th></tr></thead>
        <tbody>
          @forelse(($report['most_used_packs'] ?? []) as $pack)
            <tr><td>{{ $pack['id'] }}</td><td>{{ $pack['name'] }}</td><td>{{ number_format($pack['ownerships']) }}</td><td>{{ number_format($pack['price_coins']) }}</td></tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No pack usage yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Ownership History</h5>
      <form method="get" class="d-flex gap-2">
        <select name="origin" class="form-select form-select-sm">
          <option value="">All sources</option>
          <option value="purchased" @selected($origin === 'purchased')>Purchased</option>
          <option value="gifted" @selected($origin === 'gifted')>Gifted</option>
          <option value="admin_grant" @selected($origin === 'admin_grant')>Admin grant</option>
          <option value="admin_charged" @selected($origin === 'admin_charged')>Admin charged</option>
        </select>
        <select name="status" class="form-select form-select-sm">
          <option value="">All statuses</option>
          <option value="active" @selected($status === 'active')>Active</option>
          <option value="inactive" @selected($status === 'inactive')>Inactive</option>
          <option value="expired" @selected($status === 'expired')>Expired</option>
        </select>
        <button class="btn btn-sm btn-primary">Apply</button>
        <a href="{{ route('admin.entry-packs.reports') }}" class="btn btn-sm btn-light border">Reset</a>
      </form>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>#</th><th>User</th><th>Pack</th><th>Source</th><th>Trace</th><th>Purchased</th><th>Expires</th><th>Status</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          @forelse($recentPurchases as $purchase)
            <tr>
              <td>{{ $purchase->id }}</td>
              <td>
                <div class="fw-semibold">
                  @if($purchase->user)
                    <a href="{{ route('admin.users.show', $purchase->user) }}">{{ $purchase->user->name }}</a>
                  @else
                    User
                  @endif
                </div>
                <div class="small text-muted">{{ $purchase->user?->email }}</div>
              </td>
              <td>{{ $purchase->entryPack?->name ?? 'Pack' }}</td>
              <td>
                <span class="badge {{ $purchase->origin_badge_class }}">{{ $purchase->origin_label }}</span>
                <div class="small text-muted">{{ $purchase->charged ? 'Coins charged' : 'No wallet charge' }}</div>
              </td>
              <td class="small text-muted" style="min-width: 220px;">
                <div class="fw-semibold text-body">{{ $purchase->origin_description }}</div>
                <div>Source: {{ $purchase->source ?: 'legacy' }}</div>
                <div>Price: {{ number_format((int) ($purchase->price_coins ?: $purchase->entryPack?->price_coins ?? 0)) }} coins</div>
                @if($purchase->wallet_transaction_id)
                  <div>Wallet Tx: #{{ $purchase->wallet_transaction_id }}</div>
                @endif
                @if($purchase->grantedByAdmin)
                  <div>Admin: {{ $purchase->grantedByAdmin->name }}</div>
                @endif
                @if($purchase->admin_note)
                  <div>Note: {{ \Illuminate\Support\Str::limit($purchase->admin_note, 80) }}</div>
                @endif
              </td>
              <td>{{ optional($purchase->purchased_at)->format('d M Y H:i') }}</td>
              <td>{{ optional($purchase->expires_at)->format('d M Y H:i') ?: 'Not set' }}</td>
              <td>{!! $purchase->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
              <td class="text-end">
                @if($purchase->user)
                  <a class="btn btn-sm btn-light border" href="{{ route('admin.users.show', $purchase->user) }}">Profile</a>
                @endif
                <a class="btn btn-sm btn-light border" href="{{ route('admin.entry-packs.purchases.edit', $purchase) }}">Edit</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No ownership records yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end">{{ $recentPurchases->links() }}</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h5 class="mb-1">Paid Purchase History</h5>
      <div class="small text-muted">Wallet-backed entry-pack purchases only. Fortune Wheel grants are excluded.</div>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Transaction</th><th>User</th><th>Pack</th><th>Coins</th><th>Created</th></tr></thead>
        <tbody>
          @forelse($paidPurchases as $purchaseEvent)
            @php($purchasePack = $purchasePacks->get((int) data_get($purchaseEvent->meta, 'entry_pack_id')))
            <tr>
              <td class="fw-semibold">#{{ $purchaseEvent->id }}</td>
              <td>{{ $purchaseEvent->wallet?->user?->name ?? 'User' }}<div class="small text-muted">#{{ $purchaseEvent->wallet?->user?->id }} · {{ $purchaseEvent->wallet?->user?->email }}</div></td>
              <td>{{ $purchasePack?->name ?? data_get($purchaseEvent->meta, 'entry_pack_name', 'Pack') }}</td>
              <td>{{ number_format((int) $purchaseEvent->coins) }}</td>
              <td>{{ optional($purchaseEvent->created_at)->format('d M Y H:i:s') }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No paid purchases yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end">{{ $paidPurchases->links() }}</div>
  </div>

  <div class="card">
    <div class="card-header">
      <h5 class="mb-1">Fortune Wheel Grant History</h5>
      <div class="small text-muted">One immutable row per winning spin, even when repeated wins extend the same ownership.</div>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Spin</th><th>User</th><th>Pack</th><th>Ownership</th><th>Duration</th><th>Business Date</th><th>Created</th></tr></thead>
        <tbody>
          @forelse($wheelGrants as $grant)
            <tr>
              <td class="fw-semibold">#{{ $grant->id }}</td>
              <td>{{ $grant->user?->name ?? 'User' }}<div class="small text-muted">#{{ $grant->user_id }} · {{ $grant->user?->email }}</div></td>
              <td>{{ $grant->entryPack?->name ?? 'Pack' }}</td>
              <td>#{{ $grant->user_entry_pack_id }}<div class="small text-muted">Expires {{ optional($grant->userEntryPack?->expires_at)->format('d M Y H:i') ?: 'not set' }}</div></td>
              <td>{{ number_format((int) $grant->reward_duration_hours) }} hours</td>
              <td>{{ optional($grant->spun_for_date)->format('Y-m-d') }}</td>
              <td>{{ optional($grant->created_at)->format('d M Y H:i:s') }}</td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No Fortune Wheel entry-pack grants yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end">{{ $wheelGrants->links() }}</div>
  </div>
</div>
@endsection
