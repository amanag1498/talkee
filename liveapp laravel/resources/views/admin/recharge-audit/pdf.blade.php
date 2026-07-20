<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Recharge Audit {{ $selectedMonth->format('F Y') }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; }
    .header { margin-bottom: 20px; }
    .title { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
    .subtitle { color: #6b7280; margin: 0; }
    .cards { width: 100%; border-collapse: separate; border-spacing: 10px 0; margin: 16px 0 22px; }
    .cards td { border: 1px solid #dbe1ea; border-radius: 12px; padding: 12px; vertical-align: top; }
    .label { color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .value { font-size: 18px; font-weight: 700; margin-top: 4px; }
    table.audit { width: 100%; border-collapse: collapse; }
    table.audit th, table.audit td { border: 1px solid #dbe1ea; padding: 8px; text-align: left; }
    table.audit th { background: #f5f7fb; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .muted { color: #6b7280; }
    .status { font-weight: 700; }
    .footer-note { margin-top: 16px; color: #6b7280; font-size: 11px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="title">Recharge Audit</div>
    <p class="subtitle">{{ $selectedMonth->format('F Y') }} finance ledger snapshot</p>
  </div>

  <table class="cards">
    <tr>
      <td>
        <div class="label">Orders</div>
        <div class="value">{{ number_format((int) ($summary->total_orders ?? 0)) }}</div>
      </td>
      <td>
        <div class="label">Successful</div>
        <div class="value">{{ number_format((int) ($summary->successful_orders ?? 0)) }}</div>
      </td>
      <td>
        <div class="label">Gross Amount</div>
        <div class="value">Rs {{ number_format((float) ($summary->rupees_total ?? 0), 2) }}</div>
      </td>
      <td>
        <div class="label">Taxable Amount</div>
        <div class="value">Rs {{ number_format((float) ($summary->taxable_total ?? 0), 2) }}</div>
      </td>
      <td>
        <div class="label">GST @ 18%</div>
        <div class="value">Rs {{ number_format((float) ($summary->gst_total ?? 0), 2) }}</div>
      </td>
      <td>
        <div class="label">Coins</div>
        <div class="value">{{ number_format((int) ($summary->coins_total ?? 0)) }}</div>
      </td>
    </tr>
  </table>

  <table class="audit">
    <thead>
      <tr>
        <th>Order</th>
        <th>User</th>
        <th>Gateway Meta</th>
        <th>Gross Amount</th>
        <th>Taxable Amount</th>
        <th>GST (18%)</th>
        <th>Coins</th>
        <th>Created</th>
      </tr>
    </thead>
    <tbody>
      @forelse($orders as $order)
        @php
          $grossAmount = (float) $order->amount_rupees;
          $taxableAmount = round($grossAmount / 1.18, 2);
          $gstAmount = round($grossAmount - $taxableAmount, 2);
        @endphp
        <tr>
          <td>
            <div>{{ $order->order_id }}</div>
            <div class="muted">{{ $order->gateway_order_id ?: ($order->gateway_payment_id ?: '—') }}</div>
          </td>
          <td>
            <div>{{ $order->user?->name ?? 'User #'.$order->user_id }}</div>
            <div class="muted">{{ $order->user?->email ?? '—' }}</div>
          </td>
          <td>
            @php($meta = $order->audit_meta ?? [])
            <div>Method: {{ $meta['method'] ?? '—' }}</div>
            <div>RRN: {{ $meta['rrn'] ?? '—' }}</div>
            <div>VPA: {{ $meta['vpa'] ?? '—' }}</div>
            <div>Flow: {{ $meta['upi_flow'] ?? '—' }}</div>
            <div>Payer Type: {{ $meta['payer_account_type'] ?? '—' }}</div>
            <div>Contact: {{ $meta['contact'] ?? '—' }}</div>
            <div>Email: {{ $meta['email'] ?? '—' }}</div>
            <div>Gateway Status: {{ $meta['payment_status'] ?? '—' }}</div>
            <div>Signature: {{ array_key_exists('signature_verified', $meta) ? (($meta['signature_verified'] ?? false) ? 'Verified' : 'No') : '—' }}</div>
          </td>
          <td>Rs {{ number_format($grossAmount, 2) }}</td>
          <td>Rs {{ number_format($taxableAmount, 2) }}</td>
          <td>Rs {{ number_format($gstAmount, 2) }}</td>
          <td>{{ number_format((int) $order->total_coins) }}</td>
          <td>{{ $order->created_at?->format('d M Y, h:i A') }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="8" class="muted">No recharge orders found for this period.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  <div class="footer-note">
    @if(!empty($filters['status']) || !empty($filters['gateway']) || !empty($filters['q']) || !empty($filters['payment_method']) || !empty($filters['vpa']) || !empty($filters['rrn']) || !empty($filters['contact']) || !empty($filters['email']) || !empty($filters['signature_verified']))
      Filtered export.
      @if(!empty($filters['status'])) Status: {{ $filters['status'] }}. @endif
      @if(!empty($filters['gateway'])) Gateway: {{ $filters['gateway'] }}. @endif
      @if(!empty($filters['q'])) Search: {{ $filters['q'] }}. @endif
      @if(!empty($filters['payment_method'])) Method: {{ $filters['payment_method'] }}. @endif
      @if(!empty($filters['vpa'])) VPA: {{ $filters['vpa'] }}. @endif
      @if(!empty($filters['rrn'])) RRN: {{ $filters['rrn'] }}. @endif
      @if(!empty($filters['contact'])) Contact: {{ $filters['contact'] }}. @endif
      @if(!empty($filters['email'])) Email: {{ $filters['email'] }}. @endif
      @if($filters['signature_verified'] !== null && $filters['signature_verified'] !== '') Signature: {{ $filters['signature_verified'] === '1' ? 'Verified' : 'Not verified' }}. @endif
    @else
      Generated from Talkieo admin recharge audit.
    @endif
  </div>
</body>
</html>
