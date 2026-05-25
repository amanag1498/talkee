@extends('layouts.admin-berry')
@section('title','Recharge Audit')

@section('content')
@php
  $summary = $summary ?? null;
@endphp
<div class="admin-page-shell">
  <section class="card">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <div class="admin-page-eyebrow"><i class="ti ti-file-invoice"></i> Recharge Ledger</div>
          <h2 class="admin-page-title mt-3 mb-1">Monthly Recharge Audit</h2>
          <p class="admin-page-subtitle mb-0">Track recharge order outcomes, gateway-level success rates, and downloadable month snapshots for finance review.</p>
        </div>
        <div class="admin-page-actions">
          <a href="{{ route('admin.recharge-audit.pdf', ['month' => $selectedMonthKey] + request()->only(['status', 'gateway', 'q'])) }}" class="btn btn-primary">
            <i class="ti ti-file-download me-1"></i>Download PDF
          </a>
        </div>
      </div>

      <form method="get" action="{{ route('admin.recharge-audit.index') }}" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Month</label>
          <input type="month" name="month" value="{{ request('month', $selectedMonthKey) }}" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">Any</option>
            @foreach(['success','pending','failed','cancelled'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Gateway</label>
          <input type="text" name="gateway" value="{{ request('gateway') }}" class="form-control" placeholder="razorpay">
        </div>
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="user, email, order id">
        </div>
        <div class="col-md-1 d-grid">
          <button class="btn btn-primary"><i class="ti ti-search"></i></button>
        </div>
      </form>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Orders</div>
          <div class="fs-2 fw-bold">{{ number_format((int) ($summary->total_orders ?? 0)) }}</div>
          <div class="small text-muted mt-1">{{ $selectedMonth->format('F Y') }}</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Successful</div>
          <div class="fs-2 fw-bold text-success">{{ number_format((int) ($summary->successful_orders ?? 0)) }}</div>
          <div class="small text-muted mt-1">Pending {{ number_format((int) ($summary->pending_orders ?? 0)) }}</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Gross Amount</div>
          <div class="fs-2 fw-bold">Rs {{ number_format((float) ($summary->rupees_total ?? 0), 2) }}</div>
          <div class="small text-muted mt-1">GST inclusive</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Taxable Amount</div>
          <div class="fs-2 fw-bold">Rs {{ number_format((float) ($summary->taxable_total ?? 0), 2) }}</div>
          <div class="small text-muted mt-1">Base amount</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">GST @ 18%</div>
          <div class="fs-2 fw-bold">Rs {{ number_format((float) ($summary->gst_total ?? 0), 2) }}</div>
          <div class="small text-muted mt-1">Tax component</div>
        </div>
      </div>
    </div>
    <div class="col-xl-2 col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted small">Coins</div>
          <div class="fs-2 fw-bold">{{ number_format((int) ($summary->coins_total ?? 0)) }}</div>
          <div class="small text-muted mt-1">Recharge credits</div>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h5 class="mb-1">Monthly Tabs</h5>
          <p class="text-muted mb-0 small">Jump between the latest recharge months and export the selected month.</p>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        @forelse($monthTabs as $tab)
          <a href="{{ route('admin.recharge-audit.index', ['month' => $tab->month_key]) }}" class="btn {{ $selectedMonthKey === $tab->month_key ? 'btn-primary' : 'btn-light border' }}">
            {{ \Carbon\Carbon::createFromFormat('Y-m', $tab->month_key)->format('M Y') }}
            <span class="ms-1 small">{{ number_format((int) $tab->order_count) }}</span>
          </a>
        @empty
          <span class="text-muted">No recharge history available yet.</span>
        @endforelse
      </div>
    </div>
  </section>

  <section class="row g-3">
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-0">Gateway Breakdown</h5>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>Gateway</th>
                <th>Orders</th>
                <th>Success</th>
                <th>Rs</th>
              </tr>
            </thead>
            <tbody>
              @forelse($gatewayBreakdown as $gateway)
                <tr>
                  <td class="text-capitalize fw-semibold">{{ $gateway->gateway_name }}</td>
                  <td>{{ number_format((int) $gateway->order_count) }}</td>
                  <td>{{ number_format((int) $gateway->success_count) }}</td>
                  <td>Rs {{ number_format((float) $gateway->rupees_total, 2) }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-center text-muted py-4">No recharge orders found for this month.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            <h5 class="mb-1">Recharge Orders</h5>
            <div class="small text-muted">Order-level audit for {{ $selectedMonth->format('F Y') }}</div>
          </div>
          <div class="small text-muted">Showing {{ $orders->firstItem() ?? 0 }}-{{ $orders->lastItem() ?? 0 }} of {{ $orders->total() }}</div>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>Order</th>
                <th>User</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Gateway</th>
                <th>Value</th>
                <th>Coins</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              @forelse($orders as $order)
                <tr>
                  <td>
                    <div class="fw-semibold">{{ $order->order_id }}</div>
                    <div class="small text-muted">{{ $order->gateway_order_id ?: ($order->gateway_payment_id ?: '—') }}</div>
                  </td>
                  <td>
                    <div class="fw-semibold">{{ $order->user?->name ?? 'User #'.$order->user_id }}</div>
                    <div class="small text-muted">{{ $order->user?->email ?? '—' }}</div>
                  </td>
                  <td>{{ $order->rechargePlan?->title ?? 'Plan #'.$order->recharge_plan_id }}</td>
                  <td>
                    <span class="badge {{ match($order->status) {
                      'success' => 'bg-success-subtle text-success',
                      'pending' => 'bg-warning-subtle text-warning',
                      'failed', 'cancelled' => 'bg-danger-subtle text-danger',
                      default => 'bg-secondary-subtle text-secondary',
                    } }}">
                      {{ ucfirst($order->status) }}
                    </span>
                  </td>
                  <td class="text-capitalize">{{ $order->gateway ?: 'manual' }}</td>
                  <td>Rs {{ number_format((float) $order->amount_rupees, 2) }}</td>
                  <td>{{ number_format((int) $order->total_coins) }}</td>
                  <td>{{ $order->created_at?->format('d M Y, h:i A') }}</td>
                </tr>
              @empty
                <tr><td colspan="8" class="text-center text-muted py-5">No recharge orders found for the selected month.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div class="card-footer">
          {{ $orders->links() }}
        </div>
      </div>
    </div>
  </section>
</div>
@endsection
