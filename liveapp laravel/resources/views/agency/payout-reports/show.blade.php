@extends('layouts.agency-berry')
@section('title', 'Payout Report #' . $report->id)
@section('page_intro', 'Published agency settlement report with host-wise payout totals and PDF export.')

@section('content')
  <div class="d-flex gap-2 justify-content-end mb-3">
    <a href="{{ route('agency.payout-reports.index') }}" class="btn btn-light border">Back</a>
    <a href="{{ route('agency.payout-reports.export', $report) }}" class="btn btn-primary">Download PDF</a>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Status</small><div class="fs-5 fw-semibold mt-1">{{ ucwords(str_replace('_', ' ', $report->status)) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Hosts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_hosts) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Active Hosts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->active_hosts_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Published</small><div class="fs-5 fw-semibold mt-1">{{ optional($report->published_at)->format('d M Y H:i') }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Commission Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_agency_commission_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Coins To Be Paid</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_coins_to_be_paid) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total INR</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_inr, 2) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_pk_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_call_coins) }} / {{ number_format($report->total_video_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_call_coins) }} / {{ number_format($report->total_audio_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Bonus Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_bonus_coins) }}</div></div></div></div>
  </div>

  @if($report->admin_remarks)
    <div class="alert alert-light border">{{ $report->admin_remarks }}</div>
  @endif

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Host Settlement Breakdown</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Host</th>
            <th>Total Video Room Timing</th>
            <th>Total Audio Room Timing</th>
            <th>Total Video Room Gifts</th>
            <th>Total Audio Room Gifts</th>
            <th>Total PK Gifts</th>
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
          @forelse($report->items as $item)
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
          @empty
            <tr><td colspan="16" class="text-center text-muted py-4">No host rows in this report.</td></tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr class="fw-semibold">
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
    </div>
  </div>
@endsection
