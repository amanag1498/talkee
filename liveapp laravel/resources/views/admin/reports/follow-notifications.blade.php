@extends('layouts.admin-berry')

@section('title', 'Follow Alerts')

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <span class="admin-page-eyebrow"><i class="ti ti-bell-ringing"></i> Notification Log</span>
    <h1 class="admin-page-title">Follow Alerts</h1>
    <p class="admin-page-subtitle">Track persisted host-online and host-available notifications sent to followers.</p>
  </section>

  <div class="card">
    <div class="card-header">
      <h5 class="mb-0">Alert History</h5>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>User</th>
            <th>Type</th>
            <th>Title</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          @forelse($items as $item)
            <tr>
              <td>
                <div class="fw-semibold">{{ $item->user?->name ?: 'User #'.$item->user_id }}</div>
                <div class="text-muted small">{{ $item->user?->email }}</div>
              </td>
              <td><span class="badge bg-primary">{{ $item->type }}</span></td>
              <td>
                <div class="fw-semibold">{{ $item->title }}</div>
                <div class="text-muted small">{{ $item->body }}</div>
              </td>
              <td>
                @if($item->read_at)
                  <span class="badge bg-success">Read</span>
                @else
                  <span class="badge bg-warning text-dark">Unread</span>
                @endif
              </td>
              <td>{{ optional($item->created_at)->format('d M Y, H:i') }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No follow alerts recorded.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="d-flex justify-content-end">{{ $items->links() }}</div>
    </div>
  </div>
</div>
@endsection
