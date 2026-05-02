@extends('layouts.admin-berry')
@section('title','Entry Pack Reports')

@section('content')
<div class="admin-section-stack">
  <div class="row g-3">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Purchases</div><div class="h3 mb-0">{{ number_format($report['purchases'] ?? 0) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Coins Spent</div><div class="h3 mb-0">{{ number_format($report['coins_spent'] ?? 0) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Active Users</div><div class="h3 mb-0">{{ number_format($report['active_users'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Expired Ownerships</div><div class="h3 mb-0">{{ number_format($report['expired_owned'] ?? 0) }}</div></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Expiry Churn</div><div class="h3 mb-0">{{ number_format($report['expiry_churn_rate'] ?? 0, 1) }}%</div></div></div></div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Most Used Packs</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>#</th><th>Name</th><th>Purchases</th><th>Price</th></tr></thead>
        <tbody>
          @forelse(($report['most_used_packs'] ?? []) as $pack)
            <tr><td>{{ $pack['id'] }}</td><td>{{ $pack['name'] }}</td><td>{{ number_format($pack['purchases']) }}</td><td>{{ number_format($pack['price_coins']) }}</td></tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No pack usage yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Recent Purchases</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>#</th><th>User</th><th>Pack</th><th>Style</th><th>Purchased</th><th>Expires</th><th>Status</th><th class="text-end">Action</th></tr></thead>
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
              <td>{{ strtoupper($purchase->entryPack?->animation_style ?? 'banner') }}</td>
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
            <tr><td colspan="8" class="text-center text-muted py-4">No purchases yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end">{{ $recentPurchases->links() }}</div>
  </div>
</div>
@endsection
