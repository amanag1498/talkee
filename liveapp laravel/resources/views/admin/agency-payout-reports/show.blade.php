@extends('layouts.admin-berry')
@section('title', 'Agency Payout Report #' . $report->id)

@php
  $locked = $report->published_at || $report->status === 'paid';
@endphp

@section('content')
<style>
  .payout-grid-table { white-space: nowrap; }
  .payout-grid-table th, .payout-grid-table td { vertical-align: middle; }
  .payout-grid-sticky-left {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 2;
    min-width: 220px;
  }
  .payout-grid-sticky-right {
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 2;
    min-width: 96px;
  }
  .payout-grid-table tfoot td {
    position: sticky;
    bottom: 0;
    background: #f8fafc;
    z-index: 1;
    font-weight: 700;
  }
  .payout-grid-input {
    width: 118px;
    min-width: 118px;
    text-align: right;
    font-size: .82rem;
    line-height: 1.35;
    color: #111827;
  }
  .payout-grid-input-wide {
    width: 132px;
    min-width: 132px;
  }
  .payout-grid-input-note {
    width: 220px;
    min-width: 220px;
    white-space: normal;
    font-size: .82rem;
    line-height: 1.35;
    color: #111827;
  }
</style>

<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-receipt-2"></i>Payout Detail</span>
        <h1 class="admin-page-title">{{ $report->agency?->name ?? 'Agency' }} · Report #{{ $report->id }}</h1>
        <p class="admin-page-subtitle">
          {{ optional($report->period_start)->format('d M Y H:i') }} to {{ optional($report->period_end)->format('d M Y H:i') }} ·
          Status: {{ ucwords(str_replace('_', ' ', $report->status)) }} ·
          Agency visibility: {{ $report->published_at ? 'Published' : 'Draft only' }}
        </p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.agency-payout-reports.index') }}" class="btn btn-light border">Back</a>
          <a href="{{ route('admin.agencies.dashboard', $report->agency_id) }}" class="btn btn-outline-secondary">Open Agency Dashboard</a>
          <a href="{{ route('admin.agency-payout-reports.export', $report) }}" class="btn btn-outline-primary">Download PDF</a>
        </div>
      </div>
    </div>
  </section>

  @if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <section class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Hosts</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->total_hosts) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Active Hosts</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->active_hosts_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Coins</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->total_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total Coins To Be Paid</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->total_coins_to_be_paid) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_pk_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_call_coins) }} / {{ number_format($report->total_video_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_call_coins) }} / {{ number_format($report->total_audio_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Bonus Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_bonus_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Commission Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_agency_commission_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Total INR</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_inr, 2) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Published</small><div class="fs-5 fw-semibold mt-1">{{ $report->published_at ? optional($report->published_at)->format('d M Y H:i') : 'Not yet' }}</div><div class="text-muted small mt-1">{{ $report->publishedByAdmin?->name ?? 'Agency cannot see this yet' }}</div></div></div></div>
  </section>

  <section class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Review</h5></div>
        <div class="card-body">
          <form method="post" action="{{ route('admin.agency-payout-reports.review', $report) }}" class="row g-3">
            @csrf
            <input type="hidden" name="deductions" value="0">
            <div class="col-12">
              <label class="form-label">Admin Remarks</label>
              <textarea name="admin_remarks" class="form-control" rows="3">{{ old('admin_remarks', $report->admin_remarks) }}</textarea>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-light border" @disabled(!in_array($report->status, ['generated', 'pending_review']))>Save Pending Review</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Actions</h5></div>
        <div class="card-body d-grid gap-3">
          <form method="post" action="{{ route('admin.agency-payout-reports.approve', $report) }}" class="row g-2">
            @csrf
            <input type="hidden" name="deductions" value="0">
            <div class="col-md-12">
              <input type="text" name="admin_remarks" class="form-control" value="{{ $report->admin_remarks }}" placeholder="Approval remarks">
            </div>
            <div class="col-12">
              <button class="btn btn-primary" @disabled(!in_array($report->status, ['generated', 'pending_review']))>Approve Report</button>
            </div>
          </form>

          <form method="post" action="{{ route('admin.agency-payout-reports.publish', $report) }}" class="row g-2">
            @csrf
            <div class="col-md-12">
              <input type="text" name="admin_remarks" class="form-control" value="{{ $report->admin_remarks }}" placeholder="Publish remarks">
            </div>
            <div class="col-12">
              <button class="btn btn-outline-primary" @disabled($report->status !== 'approved' || $report->published_at)>Publish To Agency</button>
            </div>
          </form>

          <form method="post" action="{{ route('admin.agency-payout-reports.mark-paid', $report) }}" class="row g-2">
            @csrf
            <div class="col-md-12">
              <input type="text" name="admin_remarks" class="form-control" value="{{ $report->admin_remarks }}" placeholder="Paid remarks">
            </div>
            <div class="col-12">
              <button class="btn btn-success" @disabled($report->status !== 'approved' || !$report->published_at || $report->status === 'paid')>Mark Paid</button>
            </div>
          </form>

          @if($report->status !== 'paid')
            <form method="post" action="{{ route('admin.agency-payout-reports.destroy', $report) }}" class="row g-2" onsubmit="return confirm('Delete this payout report draft? This cannot be undone.');">
              @csrf
              @method('DELETE')
              <div class="col-md-12">
                <input type="text" name="admin_remarks" class="form-control" placeholder="Delete reason (optional)">
              </div>
              <div class="col-12">
                <button class="btn btn-outline-danger">Delete Report</button>
              </div>
            </form>
          @endif
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Host Settlement Grid</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle payout-grid-table" id="payout-grid">
        <thead class="table-light">
          <tr>
            <th class="payout-grid-sticky-left">Host</th>
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
            <th class="payout-grid-sticky-right text-end">Save</th>
          </tr>
        </thead>
        <tbody>
          @forelse($report->items as $item)
            @php($formId = 'item-form-' . $item->id)
            <tr data-payout-row>
              <td class="payout-grid-sticky-left">
                <div class="fw-semibold">{{ $item->host?->user?->name ?? $item->host?->stage_name ?? '—' }}</div>
                <div class="text-muted small">{{ $item->host?->stage_name ?? '—' }}</div>
              </td>
              <td><input type="number" min="0" name="video_room_minutes" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input calc-field" value="{{ $item->video_room_minutes }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="audio_room_minutes" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input calc-field" value="{{ $item->audio_room_minutes }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="video_gift_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->video_gift_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="audio_gift_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->audio_gift_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="pk_gift_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->pk_gift_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="video_call_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->video_call_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="video_call_minutes" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input calc-field" value="{{ $item->video_call_minutes }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="audio_call_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->audio_call_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="audio_call_minutes" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input calc-field" value="{{ $item->audio_call_minutes }}" @disabled($locked)></td>
              <td><input type="number" min="0" name="bonus_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->bonus_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" class="form-control form-control-sm payout-grid-input payout-grid-input-wide row-total-coins" value="{{ $item->total_coins }}" readonly></td>
              <td><input type="number" min="0" name="agency_commission_coins" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field coin-field" value="{{ $item->agency_commission_coins }}" @disabled($locked)></td>
              <td><input type="number" min="0" class="form-control form-control-sm payout-grid-input payout-grid-input-wide row-total-payable" value="{{ $item->total_coins_to_be_paid }}" readonly></td>
              <td><input type="number" step="0.01" min="0" name="total_inr" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field inr-field" value="{{ number_format($item->total_inr, 2, '.', '') }}" @disabled($locked)></td>
              <td>
                <textarea
                  name="admin_note"
                  form="{{ $formId }}"
                  rows="2"
                  class="form-control form-control-sm payout-grid-input-note"
                  placeholder="Admin notes"
                  @disabled($locked)
                >{{ $item->admin_note }}</textarea>
              </td>
              <td class="payout-grid-sticky-right text-end">
                <form id="{{ $formId }}" method="post" action="{{ route('admin.agency-payout-reports.items.update', [$report, $item]) }}">
                  @csrf
                </form>
                <button class="btn btn-sm btn-light border" type="submit" form="{{ $formId }}" @disabled($locked)>Save</button>
              </td>
            </tr>
          @empty
            <tr><td colspan="17" class="text-center text-muted py-4">No host rows in this report.</td></tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr>
            <td class="payout-grid-sticky-left">Grand Total</td>
            <td data-total="video_room_minutes">{{ number_format($report->total_video_room_minutes) }}</td>
            <td data-total="audio_room_minutes">{{ number_format($report->total_audio_room_minutes) }}</td>
            <td data-total="video_gift_coins">{{ number_format($report->total_video_gift_coins) }}</td>
            <td data-total="audio_gift_coins">{{ number_format($report->total_audio_gift_coins) }}</td>
            <td data-total="pk_gift_coins">{{ number_format($report->total_pk_gift_coins) }}</td>
            <td data-total="video_call_coins">{{ number_format($report->total_video_call_coins) }}</td>
            <td data-total="video_call_minutes">{{ number_format($report->total_video_call_minutes) }}</td>
            <td data-total="audio_call_coins">{{ number_format($report->total_audio_call_coins) }}</td>
            <td data-total="audio_call_minutes">{{ number_format($report->total_audio_call_minutes) }}</td>
            <td data-total="bonus_coins">{{ number_format($report->total_bonus_coins) }}</td>
            <td data-total="total_coins">{{ number_format($report->total_coins) }}</td>
            <td data-total="agency_commission_coins">{{ number_format($report->total_agency_commission_coins) }}</td>
            <td data-total="total_coins_to_be_paid">{{ number_format($report->total_coins_to_be_paid) }}</td>
            <td data-total="total_inr">{{ number_format($report->total_inr, 2) }}</td>
            <td>—</td>
            <td class="payout-grid-sticky-right">—</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
</div>

<script>
  (() => {
    const table = document.getElementById('payout-grid');
    if (!table) return;

    const parseNumber = (value) => {
      const num = Number.parseFloat(String(value ?? '').replace(/,/g, ''));
      return Number.isFinite(num) ? num : 0;
    };

    const formatInt = (value) => Number(value || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const formatDecimal = (value) => Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const rowValue = (row, name) => parseNumber(row.querySelector(`[name="${name}"]`)?.value);

    const recalcRow = (row) => {
      const totalCoins =
        rowValue(row, 'video_gift_coins') +
        rowValue(row, 'audio_gift_coins') +
        rowValue(row, 'pk_gift_coins') +
        rowValue(row, 'video_call_coins') +
        rowValue(row, 'audio_call_coins') +
        rowValue(row, 'bonus_coins');

      const totalPayable = totalCoins + rowValue(row, 'agency_commission_coins');

      const totalCoinsInput = row.querySelector('.row-total-coins');
      const totalPayableInput = row.querySelector('.row-total-payable');
      if (totalCoinsInput) totalCoinsInput.value = String(Math.max(0, Math.round(totalCoins)));
      if (totalPayableInput) totalPayableInput.value = String(Math.max(0, Math.round(totalPayable)));
    };

    const recalcTotals = () => {
      const totals = {
        video_room_minutes: 0,
        audio_room_minutes: 0,
        video_gift_coins: 0,
        audio_gift_coins: 0,
        pk_gift_coins: 0,
        video_call_coins: 0,
        video_call_minutes: 0,
        audio_call_coins: 0,
        audio_call_minutes: 0,
        bonus_coins: 0,
        total_coins: 0,
        agency_commission_coins: 0,
        total_coins_to_be_paid: 0,
        total_inr: 0,
      };

      table.querySelectorAll('tbody tr[data-payout-row]').forEach((row) => {
        recalcRow(row);
        totals.video_room_minutes += rowValue(row, 'video_room_minutes');
        totals.audio_room_minutes += rowValue(row, 'audio_room_minutes');
        totals.video_gift_coins += rowValue(row, 'video_gift_coins');
        totals.audio_gift_coins += rowValue(row, 'audio_gift_coins');
        totals.pk_gift_coins += rowValue(row, 'pk_gift_coins');
        totals.video_call_coins += rowValue(row, 'video_call_coins');
        totals.video_call_minutes += rowValue(row, 'video_call_minutes');
        totals.audio_call_coins += rowValue(row, 'audio_call_coins');
        totals.audio_call_minutes += rowValue(row, 'audio_call_minutes');
        totals.bonus_coins += rowValue(row, 'bonus_coins');
        totals.total_coins += parseNumber(row.querySelector('.row-total-coins')?.value);
        totals.agency_commission_coins += rowValue(row, 'agency_commission_coins');
        totals.total_coins_to_be_paid += parseNumber(row.querySelector('.row-total-payable')?.value);
        totals.total_inr += rowValue(row, 'total_inr');
      });

      Object.entries(totals).forEach(([key, value]) => {
        const cell = table.querySelector(`[data-total="${key}"]`);
        if (!cell) return;
        cell.textContent = key === 'total_inr' ? formatDecimal(value) : formatInt(value);
      });
    };

    table.addEventListener('input', (event) => {
      if (!(event.target instanceof HTMLElement)) return;
      if (!event.target.closest('tr[data-payout-row]')) return;
      recalcTotals();
    });

    recalcTotals();
  })();
</script>
@endsection
