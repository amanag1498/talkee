@extends('layouts.admin-berry')
@section('title', 'Agency Wallet Report')

@section('content')
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Agency Wallet Transfer Report</h5>
      <form method="get" class="row g-2">
        <div class="col-md-3">
          <select name="agency_id" class="form-select">
            <option value="">All agencies</option>
            @foreach($agencies as $agency)
              <option value="{{ $agency->id }}" @selected(($filters['agency_id'] ?? null) == $agency->id)>{{ $agency->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <select name="direction" class="form-select">
            <option value="">All directions</option>
            <option value="admin_to_agency" @selected(($filters['direction'] ?? null) === 'admin_to_agency')>Admin to Agency</option>
            <option value="agency_to_user" @selected(($filters['direction'] ?? null) === 'agency_to_user')>Agency to User</option>
          </select>
        </div>
        <div class="col-md-2">
          <input type="number" name="target_user_id" value="{{ $filters['target_user_id'] ?? '' }}" class="form-control" placeholder="User ID">
        </div>
        <div class="col-md-2">
          <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-2">
          <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
        </div>
        <div class="col-md-1">
          <button class="btn btn-primary w-100">Go</button>
        </div>
      </form>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="border rounded-3 p-3 h-100">
            <small class="text-muted d-block">Rows</small>
            <div class="fs-4 fw-bold">{{ number_format($summary['total_rows'] ?? 0) }}</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded-3 p-3 h-100">
            <small class="text-muted d-block">Total Loaded</small>
            <div class="fs-4 fw-bold">{{ number_format($summary['total_loaded'] ?? 0) }}</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded-3 p-3 h-100">
            <small class="text-muted d-block">Base Coins Deducted</small>
            <div class="fs-4 fw-bold">{{ number_format($summary['total_distributed'] ?? 0) }}</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="border rounded-3 p-3 h-100">
            <small class="text-muted d-block">Bonus Coins Credited</small>
            <div class="fs-4 fw-bold">{{ number_format($summary['total_bonus_credited'] ?? 0) }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Transfer Rows</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Agency</th>
            <th>Direction</th>
            <th>Plan</th>
            <th>Base</th>
            <th>Bonus</th>
            <th>User Received</th>
            <th>Target User</th>
            <th>Actor</th>
            <th>Note</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          @forelse($transfers as $transfer)
            <tr>
              <td>{{ $transfer->id }}</td>
              <td>
                <div class="fw-semibold">{{ $transfer->agency?->name ?? '—' }}</div>
                <div class="text-muted small">#{{ $transfer->agency_id }}</div>
              </td>
              <td>{{ str_replace('_', ' ', ucfirst($transfer->direction)) }}</td>
              <td>{{ $transfer->rechargePlan?->title ?? data_get($transfer->meta, 'recharge_plan_title', '—') }}</td>
              <td>{{ number_format($transfer->coins) }}</td>
              <td>{{ $transfer->direction === 'agency_to_user' ? number_format($transfer->bonus_coins) : '—' }}</td>
              <td>{{ $transfer->direction === 'agency_to_user' ? number_format($transfer->total_coins) : '—' }}</td>
              <td>{{ $transfer->targetUser?->name ? $transfer->targetUser->name.' (#'.$transfer->targetUser->id.')' : '—' }}</td>
              <td>
                @if($transfer->admin)
                  {{ $transfer->admin->name }} <span class="text-muted small">(Admin)</span>
                @elseif($transfer->agencyUser)
                  {{ $transfer->agencyUser->name }} <span class="text-muted small">(Agency)</span>
                @else
                  —
                @endif
              </td>
              <td>{{ $transfer->note ?: '—' }}</td>
              <td>{{ optional($transfer->created_at)->format('d M Y, h:i A') }}</td>
            </tr>
          @empty
            <tr><td colspan="11" class="text-center text-muted py-4">No agency wallet transfers found.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="d-flex justify-content-end">{{ $transfers->links() }}</div>
    </div>
  </div>
@endsection
