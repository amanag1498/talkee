@extends('layouts.admin-berry')
@section('title','Blocked Users')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div>
      <h5 class="mb-0"><i class="ti ti-shield-lock me-2"></i>Blocked Users</h5>
      <div class="text-muted small">Permanent host-user blocks across all rooms.</div>
    </div>
    <form method="get" class="d-flex gap-2">
      <select name="host_user_id" class="form-select">
        <option value="">All hosts</option>
        @foreach($hosts as $host)
          <option value="{{ $host->id }}" @selected(request('host_user_id') == $host->id)>{{ $host->name }}</option>
        @endforeach
      </select>
      <input type="date" class="form-control" name="from" value="{{ request('from') }}">
      <input type="date" class="form-control" name="to" value="{{ request('to') }}">
      <button class="btn btn-light border">Filter</button>
    </form>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Host</th><th>Blocked User</th><th>Reason</th><th>Blocked By</th><th>Blocked Date</th><th class="text-end">Action</th></tr>
      </thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td>{{ $row->hostUser?->name ?? '—' }}</td>
          <td>{{ $row->blockedUser?->name ?? '—' }}</td>
          <td>{{ $row->reason ?: '—' }}</td>
          <td>{{ $row->blockedBy?->name ?? '—' }} <span class="text-muted">({{ $row->blocked_by_role }})</span></td>
          <td>{{ optional($row->created_at)->format('d M Y H:i') }}</td>
          <td class="text-end">
            <form method="post" action="{{ route('admin.moderation.blocked-users.unblock') }}" onsubmit="return confirm('Unblock this user?')" class="d-inline">
              @csrf
              <input type="hidden" name="host_user_id" value="{{ $row->host_user_id }}">
              <input type="hidden" name="blocked_user_id" value="{{ $row->blocked_user_id }}">
              <button class="btn btn-sm btn-danger"><i class="ti ti-lock-open me-1"></i>Unblock</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center text-muted py-4">No blocked users found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $rows->withQueryString()->links() }}</div>
</div>
@endsection
