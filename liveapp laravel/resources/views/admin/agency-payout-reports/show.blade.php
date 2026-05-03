@extends('layouts.admin-berry')
@section('title', 'Agency Payout Report #' . $report->id)

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-receipt-2"></i>Payout Detail</span>
        <h1 class="admin-page-title">{{ $report->agency?->name ?? 'Agency' }} · Report #{{ $report->id }}</h1>
        <p class="admin-page-subtitle">
          {{ optional($report->period_start)->format('d M Y H:i') }} to {{ optional($report->period_end)->format('d M Y H:i') }} ·
          Status: {{ ucwords(str_replace('_', ' ', $report->status)) }}
        </p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.agency-payout-reports.index') }}" class="btn btn-light border">Back</a>
          <a href="{{ route('admin.agencies.dashboard', $report->agency_id) }}" class="btn btn-outline-secondary">Open Agency Dashboard</a>
          <a href="{{ route('admin.agency-payout-reports.export', $report) }}" class="btn btn-outline-primary">Export CSV</a>
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
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Active/Live Hosts</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->active_hosts_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Gross Earnings</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->gross_earnings) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Final Payable</small><div class="fs-3 fw-semibold mt-1">{{ number_format($report->final_payable) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Rooms</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_room_minutes) }} min</div><div class="text-muted small mt-1">Gifts {{ number_format($report->total_video_gift_gross) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Rooms</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_room_minutes) }} min</div><div class="text-muted small mt-1">Gifts {{ number_format($report->total_audio_gift_gross) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Video Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_video_call_minutes) }} min</div><div class="text-muted small mt-1">Gross {{ number_format($report->total_video_call_gross) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Audio Calls</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_audio_call_minutes) }} min</div><div class="text-muted small mt-1">Gross {{ number_format($report->total_audio_call_gross) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Platform Comm.</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->platform_commission) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Agency Payout</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->agency_commission) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Host Payout</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->host_share) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Combined Payout</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_payout) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Deductions</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->deductions) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Call Count</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_call_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Billable Minutes</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_billable_minutes) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Gift Events / Qty</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_gift_events) }} / {{ number_format($report->total_gift_quantity) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Live Rooms A/V</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_live_room_count) }} · {{ number_format($report->total_audio_room_count) }}/{{ number_format($report->total_video_room_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">PK Gross / Events</small><div class="fs-5 fw-semibold mt-1">{{ number_format($report->total_pk_earnings) }} / {{ number_format($report->total_pk_event_count) }}</div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="card"><div class="card-body"><small class="text-muted">Report Timezone</small><div class="fs-5 fw-semibold mt-1">{{ data_get($report->meta, 'timezone', config('app.timezone')) }}</div></div></div></div>
  </section>

  <section class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Review</h5></div>
        <div class="card-body">
          <form method="post" action="{{ route('admin.agency-payout-reports.review', $report) }}" class="row g-3">
            @csrf
            <div class="col-md-4">
              <label class="form-label">Deductions</label>
              <input type="number" min="0" name="deductions" class="form-control" value="{{ old('deductions', $report->deductions) }}">
            </div>
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
            <div class="col-md-4">
              <input type="number" min="0" name="deductions" class="form-control" value="{{ $report->deductions }}" placeholder="Deductions">
            </div>
            <div class="col-md-8">
              <input type="text" name="admin_remarks" class="form-control" value="{{ $report->admin_remarks }}" placeholder="Approval remarks">
            </div>
            <div class="col-12">
              <button class="btn btn-primary" @disabled(!in_array($report->status, ['generated', 'pending_review']))>Approve Report</button>
            </div>
          </form>

          <form method="post" action="{{ route('admin.agency-payout-reports.mark-paid', $report) }}" class="row g-2">
            @csrf
            <div class="col-12">
              <input type="text" name="admin_remarks" class="form-control" value="{{ $report->admin_remarks }}" placeholder="Payment remarks">
            </div>
            <div class="col-12">
              <button class="btn btn-success" @disabled($report->status !== 'approved' || $report->status === 'paid')>Mark as Paid</button>
            </div>
          </form>

          <form method="post" action="{{ route('admin.agency-payout-reports.reject', $report) }}" class="row g-2">
            @csrf
            <div class="col-12">
              <textarea name="admin_remarks" class="form-control" rows="3" placeholder="Rejection reason" @disabled($report->status === 'paid')></textarea>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-danger" @disabled(!in_array($report->status, ['generated', 'pending_review']))>Reject Report</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Per-Host Breakdown</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Host</th>
            <th>Video Room Min</th>
            <th>Video Gifts</th>
            <th>Audio Room Min</th>
            <th>Audio Gifts</th>
            <th>Video Call Min / Earn</th>
            <th>Audio Call Min / Earn</th>
            <th>Gross</th>
            <th>Host Payout</th>
            <th>Agency Payout</th>
            <th>Total Payout</th>
            <th>Gift Events / Qty</th>
            <th>PK Gross</th>
            <th>PK Events</th>
            <th>Final Payable</th>
          </tr>
        </thead>
        <tbody>
          @forelse($report->items as $item)
            <tr>
              <td>
                <div class="fw-semibold">{{ $item->host?->user?->name ?? $item->host?->stage_name ?? '—' }}</div>
                <div class="text-muted small">{{ $item->host?->stage_name ?? '—' }}</div>
              </td>
              <td>{{ number_format((int) data_get($item->meta, 'video_room_minutes', 0)) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'video_gift_gross', 0)) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'audio_room_minutes', 0)) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'audio_gift_gross', 0)) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'video_call_minutes', 0)) }} / {{ number_format((int) data_get($item->meta, 'video_call_gross', 0)) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'audio_call_minutes', 0)) }} / {{ number_format((int) data_get($item->meta, 'audio_call_gross', 0)) }}</td>
              <td>{{ number_format($item->gross_earnings) }}</td>
              <td>{{ number_format($item->host_payout) }} <div class="text-muted small">{{ number_format($item->host_payout_percentage, 2) }}%</div></td>
              <td>{{ number_format($item->agency_payout) }} <div class="text-muted small">{{ number_format($item->agency_payout_percentage, 2) }}%</div></td>
              <td>{{ number_format($item->total_payout) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'gift_events', 0)) }} / {{ number_format((int) data_get($item->meta, 'gift_quantity', 0)) }}</td>
              <td>{{ number_format($item->pk_earnings) }}</td>
              <td>{{ number_format((int) data_get($item->meta, 'pk_event_count', 0)) }}</td>
              <td>{{ number_format($item->final_payable) }}</td>
            </tr>
          @empty
            <tr><td colspan="15" class="text-center text-muted py-4">No host rows in this report.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  @php($recon = $reconciliation)
  <section class="mt-3">
    <div class="card mb-3">
      <div class="card-header"><h5 class="mb-0">Reconciliation Summary</h5></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Raw Call Ledger</small><div class="fs-5 fw-semibold mt-1">{{ number_format($recon['summary']['call_rows']) }} rows</div><div class="text-muted small mt-1">Gross {{ number_format($recon['summary']['call_gross']) }}</div></div></div>
          <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Raw Live Gift Ledger</small><div class="fs-5 fw-semibold mt-1">{{ number_format($recon['summary']['gift_rows']) }} rows</div><div class="text-muted small mt-1">Gross {{ number_format($recon['summary']['gift_gross']) }}</div></div></div>
          <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">PK-Linked Gift Rows</small><div class="fs-5 fw-semibold mt-1">{{ number_format($recon['summary']['pk_rows']) }} rows</div><div class="text-muted small mt-1">Gross {{ number_format($recon['summary']['pk_gross']) }}</div></div></div>
          <div class="col-md-6 col-xl-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Final Split Trace</small><div class="fs-5 fw-semibold mt-1">Host {{ number_format($recon['summary']['host_payout']) }}</div><div class="text-muted small mt-1">Agency {{ number_format($recon['summary']['agency_payout']) }}</div></div></div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h5 class="mb-0">Final Host / Agency Split Trace</h5></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Host</th>
              <th>Call Gross</th>
              <th>Live Gift Gross</th>
              <th>PK Gross</th>
              <th>Gross Used</th>
              <th>Host % / Payout</th>
              <th>Agency % / Payout</th>
              <th>Total Payout</th>
              <th>Final Payable</th>
            </tr>
          </thead>
          <tbody>
            @forelse($recon['split_rows'] as $item)
              <tr>
                <td>
                  <div class="fw-semibold">{{ $item->host?->user?->name ?? $item->host?->stage_name ?? '—' }}</div>
                  <div class="text-muted small">{{ $item->host?->stage_name ?? '—' }}</div>
                </td>
                <td>{{ number_format($item->call_earnings) }}</td>
                <td>{{ number_format($item->gift_earnings) }}</td>
                <td>{{ number_format($item->pk_earnings) }}</td>
                <td>{{ number_format($item->gross_earnings) }}</td>
                <td>{{ number_format($item->host_payout_percentage, 2) }}% / {{ number_format($item->host_payout) }}</td>
                <td>{{ number_format($item->agency_payout_percentage, 2) }}% / {{ number_format($item->agency_payout) }}</td>
                <td>{{ number_format($item->total_payout) }}</td>
                <td>{{ number_format($item->final_payable) }}</td>
              </tr>
            @empty
              <tr><td colspan="9" class="text-center text-muted py-4">No split rows found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h5 class="mb-0">Raw Call Ledger Rows</h5></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Ledger</th>
              <th>Host</th>
              <th>Caller</th>
              <th>Session</th>
              <th>Type / Status</th>
              <th>Billable Min</th>
              <th>Total Coins</th>
              <th>Host / Agency / Platform</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            @forelse($recon['call_rows'] as $row)
              <tr>
                <td>#{{ $row->id }}</td>
                <td>{{ $row->host?->user?->name ?? $row->host?->stage_name ?? '—' }}</td>
                <td>{{ $row->caller?->name ?? '—' }}</td>
                <td>#{{ $row->call_session_id }}</td>
                <td>{{ strtoupper((string) ($row->callSession?->type ?? '—')) }} / {{ $row->callSession?->status ?? '—' }}</td>
                <td>{{ number_format($row->billable_minutes) }}</td>
                <td>{{ number_format($row->total_coins) }}</td>
                <td>{{ number_format($row->host_earning) }} / {{ number_format($row->agency_earning) }} / {{ number_format($row->platform_earning) }}</td>
                <td>{{ optional($row->created_at)->format('d M Y H:i') }}</td>
              </tr>
            @empty
              <tr><td colspan="9" class="text-center text-muted py-4">No call ledger rows found in this report period.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><h5 class="mb-0">Raw Live Gift Ledger Rows</h5></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>Ledger</th>
              <th>Host</th>
              <th>Sender</th>
              <th>Room</th>
              <th>Gift</th>
              <th>Qty</th>
              <th>Total Coins</th>
              <th>Host / Agency / Platform</th>
              <th>Txn</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            @forelse($recon['gift_rows'] as $row)
              <tr>
                <td>#{{ $row->id }}</td>
                <td>{{ $row->host?->user?->name ?? $row->host?->stage_name ?? '—' }}</td>
                <td>{{ $row->sender?->name ?? '—' }}</td>
                <td>
                  <div class="fw-semibold">{{ $row->room?->title ?? ($row->room?->room_id ? 'Room '.$row->room->room_id : '—') }}</div>
                  <div class="text-muted small">{{ strtoupper((string) ($row->room?->room_type ?? '—')) }}</div>
                </td>
                <td>{{ $row->roomGift?->gift?->name ?? '—' }}</td>
                <td>{{ number_format((int) ($row->roomGift?->quantity ?? 0)) }}</td>
                <td>{{ number_format($row->total_coins) }}</td>
                <td>{{ number_format($row->host_payout_coins) }} / {{ number_format($row->agency_payout_coins) }} / {{ number_format($row->platform_revenue_coins) }}</td>
                <td>{{ $row->roomGift?->transaction_id ?? '—' }}</td>
                <td>{{ optional($row->created_at)->format('d M Y H:i') }}</td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-4">No live gift ledger rows found in this report period.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h5 class="mb-0">PK-Linked Gift Rows</h5></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th>PK Event</th>
              <th>Battle</th>
              <th>Host</th>
              <th>Sender</th>
              <th>Room</th>
              <th>Gift</th>
              <th>Event / Ledger Coins</th>
              <th>Split H / A / P</th>
              <th>Wallet Txn</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            @forelse($recon['pk_gift_rows'] as $row)
              <tr>
                <td>#{{ $row->pk_event_id }}</td>
                <td>#{{ $row->pk_battle_id ?? '—' }}</td>
                <td>{{ $row->host?->user?->name ?? $row->host?->stage_name ?? '—' }}</td>
                <td>{{ $row->sender?->name ?? '—' }}</td>
                <td>
                  <div class="fw-semibold">{{ $row->room?->title ?? ($row->room?->room_id ? 'Room '.$row->room->room_id : '—') }}</div>
                  <div class="text-muted small">{{ strtoupper((string) ($row->room?->room_type ?? '—')) }}</div>
                </td>
                <td>{{ $row->roomGift?->gift?->name ?? '—' }}</td>
                <td>{{ number_format((int) ($row->pk_event_coins ?? 0)) }} / {{ number_format($row->total_coins) }}</td>
                <td>{{ number_format($row->host_payout_coins) }} / {{ number_format($row->agency_payout_coins) }} / {{ number_format($row->platform_revenue_coins) }}</td>
                <td>{{ $row->pk_wallet_transaction_id ?? ($row->roomGift?->transaction_id ?? '—') }}</td>
                <td>{{ $row->pk_created_at ? \Carbon\Carbon::parse($row->pk_created_at)->format('d M Y H:i') : optional($row->created_at)->format('d M Y H:i') }}</td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center text-muted py-4">No PK-linked gift rows found in this report period.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>
@endsection
