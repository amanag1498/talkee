@extends('layouts.admin-berry')
@section('title', 'Recharge Plans')

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-wallet"></i>Recharge Management</span>
        <h1 class="admin-page-title">Recharge Plans</h1>
        <p class="admin-page-subtitle">Create, update, activate, and sort the recharge packs exposed to the Flutter wallet summary and recharge order flow.</p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.recharge-plans.create') }}" class="btn btn-primary">Create Recharge Plan</a>
        </div>
      </div>
    </div>
  </section>

  @if(session('ok'))
    <div class="alert alert-success">{{ session('ok') }}</div>
  @endif
  @if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
  @endif

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Configured Recharge Plans</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Title</th>
            <th>Amount</th>
            <th>Coins</th>
            <th>Bonus</th>
            <th>Total</th>
            <th>Agency Bonus</th>
            <th>Agency Total</th>
            <th>Status</th>
            <th>Sort</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($plans as $plan)
            <tr>
              <td class="fw-semibold">{{ $plan->title }}</td>
              <td>₹{{ number_format((float) $plan->amount_rupees, 2) }}</td>
              <td>{{ number_format($plan->coins) }}</td>
              <td>{{ number_format($plan->bonus_coins) }}</td>
              <td>{{ number_format($plan->total_coins) }}</td>
              <td>{{ number_format($plan->agency_bonus_coins) }}</td>
              <td>{{ number_format($plan->total_coins + $plan->agency_bonus_coins) }}</td>
              <td>
                <span class="badge {{ $plan->is_active ? 'bg-success' : 'bg-secondary' }}">
                  {{ $plan->is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>{{ $plan->sort_order }}</td>
              <td class="text-end">
                <div class="d-inline-flex gap-2">
                  <a href="{{ route('admin.recharge-plans.edit', $plan) }}" class="btn btn-sm btn-light border">Edit</a>
                  <form method="post" action="{{ route('admin.recharge-plans.destroy', $plan) }}" onsubmit="return confirm('Delete this recharge plan?');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center text-muted py-4">No recharge plans configured.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
