@extends('layouts.admin-berry')
@section('title','Moderation History')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <div>
      <h5 class="mb-0"><i class="ti ti-history me-2"></i>Moderation History</h5>
      <div class="text-muted small">Global moderation actions across host and admin flows.</div>
    </div>
    <form method="get" class="d-flex gap-2">
      <input name="action_type" class="form-control" value="{{ request('action_type') }}" placeholder="Action type">
      <input name="target_user_id" class="form-control" value="{{ request('target_user_id') }}" placeholder="Target user ID">
      <button class="btn btn-light border">Filter</button>
    </form>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr><th>Action</th><th>Actor</th><th>Target</th><th>Host</th><th>Room</th><th>Reason</th><th>When</th></tr>
      </thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td><span class="badge bg-light text-dark border">{{ $row->action_type }}</span></td>
          <td>{{ $row->actor?->name ?? 'System' }} <div class="text-muted small">{{ $row->actor_role }}</div></td>
          <td>{{ $row->targetUser?->name ?? '—' }}</td>
          <td>{{ $row->hostUser?->name ?? '—' }}</td>
          <td>{{ $row->room_id ?: '—' }} @if($row->room_type)<div class="text-muted small">{{ $row->room_type }}</div>@endif</td>
          <td>{{ $row->reason ?: '—' }}</td>
          <td>{{ optional($row->created_at)->format('d M Y H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No moderation history found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $rows->withQueryString()->links() }}</div>
</div>
@endsection
