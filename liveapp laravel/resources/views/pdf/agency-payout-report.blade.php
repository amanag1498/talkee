<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Agency Payout Report #{{ $report->id }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
    h1, h2, h3, p { margin: 0; }
    .header { margin-bottom: 16px; }
    .meta { margin-top: 6px; color: #4b5563; }
    .summary { width: 100%; border-collapse: collapse; margin: 16px 0; }
    .summary td { border: 1px solid #d1d5db; padding: 8px; vertical-align: top; width: 25%; }
    .summary .label { font-size: 10px; color: #6b7280; }
    .summary .value { margin-top: 4px; font-size: 14px; font-weight: 700; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 6px; text-align: right; }
    table.grid th:first-child, table.grid td:first-child { text-align: left; }
    table.grid thead th { background: #f3f4f6; font-size: 10px; }
    table.grid tfoot td { background: #f9fafb; font-weight: 700; }
    .note { margin-top: 12px; padding: 10px; border: 1px solid #d1d5db; background: #f9fafb; }
  </style>
</head>
<body>
  <div class="header">
    <h1>{{ $report->agency?->name ?? 'Agency' }} Settlement Report</h1>
    <p class="meta">
      Report #{{ $report->id }} · {{ optional($report->period_start)->format('d M Y H:i') }} to {{ optional($report->period_end)->format('d M Y H:i') }} ·
      Status: {{ ucwords(str_replace('_', ' ', $report->status)) }} ·
      Published: {{ $report->published_at ? optional($report->published_at)->format('d M Y H:i') : 'Not yet' }}
    </p>
  </div>

  <table class="summary">
    <tr>
      <td><div class="label">Total Hosts</div><div class="value">{{ number_format($report->total_hosts) }}</div></td>
      <td><div class="label">Active Hosts</div><div class="value">{{ number_format($report->active_hosts_count) }}</div></td>
      <td><div class="label">Total Coins</div><div class="value">{{ number_format($report->total_coins) }}</div></td>
      <td><div class="label">Total Coins To Be Paid</div><div class="value">{{ number_format($report->total_coins_to_be_paid) }}</div></td>
    </tr>
    <tr>
      <td><div class="label">Video Room Timing</div><div class="value">{{ number_format($report->total_video_room_minutes) }} min</div></td>
      <td><div class="label">Audio Room Timing</div><div class="value">{{ number_format($report->total_audio_room_minutes) }} min</div></td>
      <td><div class="label">Video / Audio Gifts</div><div class="value">{{ number_format($report->total_video_gift_coins) }} / {{ number_format($report->total_audio_gift_coins) }}</div></td>
      <td><div class="label">PK Gifts</div><div class="value">{{ number_format($report->total_pk_gift_coins) }}</div></td>
    </tr>
    <tr>
      <td><div class="label">Video Calls</div><div class="value">{{ number_format($report->total_video_call_coins) }} / {{ number_format($report->total_video_call_minutes) }} min</div></td>
      <td><div class="label">Audio Calls</div><div class="value">{{ number_format($report->total_audio_call_coins) }} / {{ number_format($report->total_audio_call_minutes) }} min</div></td>
      <td><div class="label">Bonus Coins</div><div class="value">{{ number_format($report->total_bonus_coins) }}</div></td>
      <td><div class="label">Agency Commission Coins</div><div class="value">{{ number_format($report->total_agency_commission_coins) }}</div></td>
    </tr>
    <tr>
      <td><div class="label">Total INR</div><div class="value">{{ number_format($report->total_inr, 2) }}</div></td>
      <td colspan="3"><div class="label">Published By</div><div class="value">{{ $report->publishedByAdmin?->name ?? '—' }}</div></td>
    </tr>
  </table>

  <table class="grid">
    <thead>
      <tr>
        <th>Host</th>
        <th>Video Room Timing</th>
        <th>Audio Room Timing</th>
        <th>Video Gifts</th>
        <th>Audio Gifts</th>
        <th>PK Gifts</th>
        <th>Video Calls Coins</th>
        <th>Video Calls Min</th>
        <th>Audio Calls Coins</th>
        <th>Audio Calls Min</th>
        <th>Bonus Coins</th>
        <th>Total Coins</th>
        <th>Agency Commission Coins</th>
        <th>Total Coins To Be Paid</th>
        <th>Total INR</th>
        <th>Admin Notes</th>
      </tr>
    </thead>
    <tbody>
      @foreach($report->items as $item)
        <tr>
          <td>{{ $item->host?->user?->name ?? $item->host?->stage_name ?? '—' }}</td>
          <td>{{ number_format($item->video_room_minutes) }}</td>
          <td>{{ number_format($item->audio_room_minutes) }}</td>
          <td>{{ number_format($item->video_gift_coins) }}</td>
          <td>{{ number_format($item->audio_gift_coins) }}</td>
          <td>{{ number_format($item->pk_gift_coins) }}</td>
          <td>{{ number_format($item->video_call_coins) }}</td>
          <td>{{ number_format($item->video_call_minutes) }}</td>
          <td>{{ number_format($item->audio_call_coins) }}</td>
          <td>{{ number_format($item->audio_call_minutes) }}</td>
          <td>{{ number_format($item->bonus_coins) }}</td>
          <td>{{ number_format($item->total_coins) }}</td>
          <td>{{ number_format($item->agency_commission_coins) }}</td>
          <td>{{ number_format($item->total_coins_to_be_paid) }}</td>
          <td>{{ number_format($item->total_inr, 2) }}</td>
          <td>{{ $item->admin_note ?: '—' }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td>Grand Total</td>
        <td>{{ number_format($report->total_video_room_minutes) }}</td>
        <td>{{ number_format($report->total_audio_room_minutes) }}</td>
        <td>{{ number_format($report->total_video_gift_coins) }}</td>
        <td>{{ number_format($report->total_audio_gift_coins) }}</td>
        <td>{{ number_format($report->total_pk_gift_coins) }}</td>
        <td>{{ number_format($report->total_video_call_coins) }}</td>
        <td>{{ number_format($report->total_video_call_minutes) }}</td>
        <td>{{ number_format($report->total_audio_call_coins) }}</td>
        <td>{{ number_format($report->total_audio_call_minutes) }}</td>
        <td>{{ number_format($report->total_bonus_coins) }}</td>
        <td>{{ number_format($report->total_coins) }}</td>
        <td>{{ number_format($report->total_agency_commission_coins) }}</td>
        <td>{{ number_format($report->total_coins_to_be_paid) }}</td>
        <td>{{ number_format($report->total_inr, 2) }}</td>
        <td>—</td>
      </tr>
    </tfoot>
  </table>

  @if($report->admin_remarks)
    <div class="note">
      <strong>Admin Remarks:</strong><br>
      {{ $report->admin_remarks }}
    </div>
  @endif
</body>
</html>
