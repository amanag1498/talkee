@extends('layouts.admin-berry')
@section('title','Moderation Reports')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div>
      <h5 class="mb-0"><i class="ti ti-flag-3 me-2"></i>Reports Review Queue</h5>
      <div class="text-muted small">Pending and reviewed user reports.</div>
    </div>
    <form method="get" class="d-flex gap-2">
      <select name="status" class="form-select">
        <option value="">Any status</option>
        @foreach(['pending','reviewed','dismissed','action_taken'] as $status)
          <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_',' ',$status)) }}</option>
        @endforeach
      </select>
      <input class="form-control" name="reason_type" value="{{ request('reason_type') }}" placeholder="Reason type">
      <button class="btn btn-light border">Filter</button>
    </form>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Reporter</th><th>Reported User</th><th>Reason</th><th>Room/Host</th><th>Status</th><th>Created</th><th style="min-width:260px">Review</th></tr>
      </thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td>{{ $row->reporter?->name ?? '—' }}</td>
          <td>{{ $row->reportedUser?->name ?? '—' }}</td>
          <td>
            <div class="fw-semibold">{{ $row->reason_type }}</div>
            @if($row->description)<div class="text-muted small">{{ $row->description }}</div>@endif
          </td>
          <td>{{ $row->room_id ?: '—' }} @if($row->hostUser)<div class="text-muted small">Host: {{ $row->hostUser->name }}</div>@endif</td>
          <td><span class="badge bg-light text-dark border">{{ $row->status }}</span></td>
          <td>{{ optional($row->created_at)->format('d M Y H:i') }}</td>
          <td>
            <form method="post" action="{{ route('admin.moderation.reports.review', $row) }}" class="d-flex gap-2">
              @csrf
              <select name="status" class="form-select form-select-sm">
                @foreach(['reviewed','dismissed','action_taken'] as $status)
                  <option value="{{ $status }}">{{ ucfirst(str_replace('_',' ',$status)) }}</option>
                @endforeach
              </select>
              <input name="admin_notes" class="form-control form-control-sm" placeholder="Admin notes">
              <button class="btn btn-sm btn-primary">Save</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No reports found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $rows->withQueryString()->links() }}</div>
</div>
@endsection
