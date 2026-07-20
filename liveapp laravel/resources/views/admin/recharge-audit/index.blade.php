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
          <h2 class="admin-page-title mt-3 mb-1">Recharge Audit</h2>
          <p class="admin-page-subtitle mb-0">Track recharge order outcomes, gateway-level success rates, and downloadable custom date-range snapshots for finance review.</p>
        </div>
        <div class="admin-page-actions">
          <a href="{{ route('admin.recharge-audit.pdf', request()->only(['from', 'to', 'status', 'gateway', 'q', 'payment_method', 'vpa', 'rrn', 'contact', 'email', 'signature_verified'])) }}" class="btn btn-primary">
            <i class="ti ti-file-download me-1"></i>Download PDF
          </a>
        </div>
      </div>

      <form method="get" action="{{ route('admin.recharge-audit.index') }}" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">From</label>
          <input type="date" name="from" value="{{ request('from', $fromDate->format('Y-m-d')) }}" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">To</label>
          <input type="date" name="to" value="{{ request('to', $toDate->format('Y-m-d')) }}" class="form-control">
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
        <div class="col-md-3">
          <label class="form-label">Method</label>
          <input type="text" name="payment_method" value="{{ request('payment_method') }}" class="form-control" placeholder="upi / card / netbanking">
        </div>
        <div class="col-md-3">
          <label class="form-label">VPA</label>
          <input type="text" name="vpa" value="{{ request('vpa') }}" class="form-control" placeholder="user@bank">
        </div>
        <div class="col-md-2">
          <label class="form-label">RRN</label>
          <input type="text" name="rrn" value="{{ request('rrn') }}" class="form-control" placeholder="Bank RRN">
        </div>
        <div class="col-md-2">
          <label class="form-label">Gateway Contact</label>
          <input type="text" name="contact" value="{{ request('contact') }}" class="form-control" placeholder="+91...">
        </div>
        <div class="col-md-3">
          <label class="form-label">Gateway Email</label>
          <input type="text" name="email" value="{{ request('email') }}" class="form-control" placeholder="payer email">
        </div>
        <div class="col-md-2">
          <label class="form-label">Signature</label>
          <select name="signature_verified" class="form-select">
            <option value="">Any</option>
            <option value="1" @selected(request('signature_verified') === '1')>Verified</option>
            <option value="0" @selected(request('signature_verified') === '0')>Not Verified</option>
          </select>
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-primary"><i class="ti ti-search me-1"></i>Apply</button>
        </div>
        <div class="col-md-2 d-grid">
          <a href="{{ route('admin.recharge-audit.index') }}" class="btn btn-light border">Reset</a>
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
          <div class="small text-muted mt-1">{{ $selectedRangeLabel }}</div>
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
                <tr><td colspan="4" class="text-center text-muted py-4">No recharge orders found for this date range.</td></tr>
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
            <div class="small text-muted">Order-level audit for {{ $selectedRangeLabel }}</div>
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
                <th>Payment Meta</th>
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
                  <td class="small text-muted">
                    @php($meta = $order->audit_meta ?? [])
                    <div><span class="fw-semibold text-dark">Method:</span> {{ $meta['method'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">RRN:</span> {{ $meta['rrn'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">VPA:</span> {{ $meta['vpa'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Flow:</span> {{ $meta['upi_flow'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Payer Type:</span> {{ $meta['payer_account_type'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Contact:</span> {{ $meta['contact'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Gateway Email:</span> {{ $meta['email'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Gateway Status:</span> {{ $meta['payment_status'] ?? '—' }}</div>
                    <div><span class="fw-semibold text-dark">Signature:</span> {{ array_key_exists('signature_verified', $meta) ? (($meta['signature_verified'] ?? false) ? 'Verified' : 'No') : '—' }}</div>
                    <div><span class="fw-semibold text-dark">Fee:</span> {{ $meta['gateway_fee'] !== null ? 'Rs '.number_format(((float) $meta['gateway_fee']) / 100, 2) : '—' }}</div>
                    <div><span class="fw-semibold text-dark">Tax:</span> {{ $meta['gateway_tax'] !== null ? 'Rs '.number_format(((float) $meta['gateway_tax']) / 100, 2) : '—' }}</div>
                    @if(!empty($meta['error_code']) || !empty($meta['error_description']))
                      <div><span class="fw-semibold text-dark">Gateway Error:</span> {{ $meta['error_code'] ?? '—' }}{{ !empty($meta['error_description']) ? ' · '.$meta['error_description'] : '' }}</div>
                    @endif
                  </td>
                  <td>Rs {{ number_format((float) $order->amount_rupees, 2) }}</td>
                  <td>{{ number_format((int) $order->total_coins) }}</td>
                  <td>{{ $order->created_at?->format('d M Y, h:i A') }}</td>
                </tr>
              @empty
                <tr><td colspan="9" class="text-center text-muted py-5">No recharge orders found for the selected date range.</td></tr>
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
