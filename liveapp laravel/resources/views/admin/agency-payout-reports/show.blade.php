@extends('layouts.admin-berry')
@section('title', 'Agency Payout Report #' . $report->id)

@php
  $locked = $report->published_at || $report->status === 'paid';
@endphp

@section('content')
<style>
  .payout-grid-shell {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    background: #ffffff;
    overflow: hidden;
  }
  .payout-grid-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid #e5e7eb;
    background: linear-gradient(180deg, #fcfcfd 0%, #f8fafc 100%);
  }
  .payout-grid-caption {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .payout-grid-caption strong {
    font-size: .95rem;
    color: #111827;
  }
  .payout-grid-caption span {
    font-size: .78rem;
    color: #6b7280;
  }
  .payout-grid-hint {
    font-size: .76rem;
    color: #6b7280;
    text-align: right;
  }
  .payout-grid-scroll {
    max-height: 72vh;
    overflow: auto;
    background: #fff;
  }
  .payout-grid-table {
    white-space: nowrap;
    margin-bottom: 0;
    min-width: 1780px;
  }
  .payout-grid-table th,
  .payout-grid-table td {
    vertical-align: middle;
    border-color: #e5e7eb;
  }
  .payout-grid-table thead th {
    position: sticky;
    top: 0;
    z-index: 4;
    background: #f8fafc;
    color: #111827;
    font-size: .76rem;
    font-weight: 700;
    line-height: 1.25;
    padding: 10px 12px;
    box-shadow: inset 0 -1px 0 #dbe3ee;
  }
  .payout-grid-table tbody td {
    padding: 10px 12px;
    background: #fff;
  }
  .payout-grid-table tbody tr:nth-child(even) td {
    background: #fcfcfd;
  }
  .payout-grid-table tbody tr:hover td {
    background: #f9fbff;
  }
  .payout-grid-sticky-left {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 3;
    min-width: 240px;
    box-shadow: 1px 0 0 #e5e7eb;
  }
  .payout-grid-sticky-right {
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 3;
    min-width: 110px;
    box-shadow: -1px 0 0 #e5e7eb;
  }
  .payout-grid-table thead .payout-grid-sticky-left,
  .payout-grid-table thead .payout-grid-sticky-right {
    z-index: 6;
    background: #eef3f9;
  }
  .payout-grid-table tfoot td {
    position: sticky;
    bottom: 0;
    background: #eef3f9;
    z-index: 2;
    font-weight: 700;
    box-shadow: inset 0 1px 0 #dbe3ee;
    padding: 10px 12px;
  }
  .payout-grid-input {
    width: 122px;
    min-width: 122px;
    text-align: right;
    font-size: .82rem;
    line-height: 1.35;
    color: #111827;
    border-radius: 10px;
    border-color: #d1d5db;
    background: #fff;
  }
  .payout-grid-input-wide {
    width: 138px;
    min-width: 138px;
  }
  .payout-grid-input-note {
    width: 260px;
    min-width: 260px;
    white-space: normal;
    font-size: .82rem;
    line-height: 1.35;
    color: #111827;
    border-radius: 10px;
    border-color: #d1d5db;
    background: #fff;
  }
  .payout-grid-row-host {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .payout-grid-row-host strong {
    font-size: .88rem;
    color: #111827;
  }
  .payout-grid-row-host span {
    font-size: .74rem;
    color: #6b7280;
  }
  .payout-grid-save-btn {
    min-width: 88px;
    border-radius: 10px;
  }
  @media (max-width: 991px) {
    .payout-grid-scroll {
      max-height: 68vh;
    }
    .payout-grid-toolbar {
      align-items: flex-start;
      flex-direction: column;
    }
    .payout-grid-hint {
      text-align: left;
    }
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
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Room Timing</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_room_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gifts</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_pk_gift_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_call_coins) }} / {{ number_format($report->total_video_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_call_coins) }} / {{ number_format($report->total_audio_call_minutes) }} min</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Bonus Coins</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_bonus_coins) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Host Payout INR</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_host_payout_inr, 2) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Commission INR</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_agency_commission_inr, 2) }}</div></div></div></div>
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

          <form method="post" action="{{ route('admin.agency-payout-reports.destroy', $report) }}" class="row g-2" onsubmit="return confirm('Delete this payout report? This cannot be undone.');">
            @csrf
            @method('DELETE')
            <div class="col-md-12">
              <input type="text" name="admin_remarks" class="form-control" placeholder="Delete reason (optional)">
            </div>
            <div class="col-12">
              <button class="btn btn-outline-danger">Delete Report</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-body">
      <div class="payout-grid-shell">
        <div class="payout-grid-toolbar">
          <div class="payout-grid-caption">
            <strong>Host Settlement Grid</strong>
            <span>Edit row values directly. Header, host, totals, and save action stay visible while scrolling.</span>
          </div>
          <div class="payout-grid-hint">
            <div>Scroll vertically to move across hosts.</div>
            <div>Scroll horizontally to review all settlement fields.</div>
          </div>
        </div>
        <div class="payout-grid-scroll">
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
            <th>Host Payout INR</th>
            <th>Agency Commission INR</th>
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
                <div class="payout-grid-row-host">
                  <strong>{{ $item->host?->user?->name ?? $item->host?->stage_name ?? '—' }}</strong>
                  <span>User ID: {{ $item->host?->user_id ?? '—' }} · {{ $item->host?->stage_name ?? '—' }}</span>
                </div>
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
              <td><input type="number" step="0.01" min="0" name="host_payout_inr" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field inr-field" value="{{ number_format($item->host_payout_inr, 2, '.', '') }}" @disabled($locked)></td>
              <td>
                <input type="hidden" name="agency_commission_coins" form="{{ $formId }}" value="{{ $item->agency_commission_coins }}">
                <input type="number" step="0.01" min="0" name="agency_commission_inr" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide calc-field inr-field" value="{{ number_format($item->agency_commission_inr, 2, '.', '') }}" @disabled($locked)>
              </td>
              <td><input type="number" step="0.01" min="0" name="total_inr" form="{{ $formId }}" class="form-control form-control-sm payout-grid-input payout-grid-input-wide row-total-inr" value="{{ number_format($item->total_inr, 2, '.', '') }}" readonly></td>
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
                <button class="btn btn-sm btn-light border payout-grid-save-btn" type="submit" form="{{ $formId }}" @disabled($locked)>Save</button>
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
            <td data-total="host_payout_inr">{{ number_format($report->total_host_payout_inr, 2) }}</td>
            <td data-total="agency_commission_inr">{{ number_format($report->total_agency_commission_inr, 2) }}</td>
            <td data-total="total_inr">{{ number_format($report->total_inr, 2) }}</td>
            <td>—</td>
            <td class="payout-grid-sticky-right">—</td>
          </tr>
        </tfoot>
      </table>
        </div>
      </div>
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

      const totalInr = rowValue(row, 'host_payout_inr') + rowValue(row, 'agency_commission_inr');

      const totalCoinsInput = row.querySelector('.row-total-coins');
      const totalInrInput = row.querySelector('.row-total-inr');
      if (totalCoinsInput) totalCoinsInput.value = String(Math.max(0, Math.round(totalCoins)));
      if (totalInrInput) totalInrInput.value = totalInr.toFixed(2);
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
        host_payout_inr: 0,
        agency_commission_inr: 0,
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
        totals.host_payout_inr += rowValue(row, 'host_payout_inr');
        totals.agency_commission_inr += rowValue(row, 'agency_commission_inr');
        totals.total_inr += parseNumber(row.querySelector('.row-total-inr')?.value);
      });

      Object.entries(totals).forEach(([key, value]) => {
        const cell = table.querySelector(`[data-total="${key}"]`);
        if (!cell) return;
        cell.textContent = ['host_payout_inr', 'agency_commission_inr', 'total_inr'].includes(key)
          ? formatDecimal(value)
          : formatInt(value);
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
