@extends('layouts.admin-berry')

@section('title', 'Host Followers')

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <span class="admin-page-eyebrow"><i class="ti ti-users-group"></i> Follow Graph</span>
    <h1 class="admin-page-title">Host Followers</h1>
    <p class="admin-page-subtitle">Review follower relationships, notification preferences, and top-followed hosts.</p>
  </section>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Top Followed Hosts</h5>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush">
            @forelse($topHosts as $host)
              <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <div>
                  <div class="fw-semibold">{{ $host->stage_name ?: $host->user?->name ?: 'Host #'.$host->id }}</div>
                  <div class="text-muted small">{{ $host->user?->email }}</div>
                </div>
                <span class="badge bg-primary">{{ $host->followers_count }}</span>
              </div>
            @empty
              <div class="text-muted">No followers yet.</div>
            @endforelse
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Follower Relationships</h5>
          <form method="get" class="d-flex gap-2">
            <select name="host_id" class="form-select">
              <option value="">All hosts</option>
              @foreach($hosts as $host)
                <option value="{{ $host->id }}" @selected((string) $hostId === (string) $host->id)>
                  {{ $host->stage_name ?: $host->user?->name ?: 'Host #'.$host->id }}
                </option>
              @endforeach
            </select>
            <button class="btn btn-light border">Filter</button>
          </form>
        </div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead class="table-light">
              <tr>
                <th>Host</th>
                <th>Follower</th>
                <th>Notify Online</th>
                <th>Notify Available</th>
                <th>Followed At</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              @forelse($rows as $row)
                <tr>
                  <td>
                    <div class="fw-semibold">{{ $row->host?->stage_name ?: $row->host?->user?->name ?: 'Host #'.$row->host_id }}</div>
                    <div class="text-muted small">{{ $row->host?->user?->email }}</div>
                  </td>
                  <td>
                    <div class="fw-semibold">{{ $row->user?->name ?: 'User #'.$row->user_id }}</div>
                    <div class="text-muted small">{{ $row->user?->email }}</div>
                  </td>
                  <td><span class="badge {{ $row->notify_when_online ? 'bg-success' : 'bg-secondary' }}">{{ $row->notify_when_online ? 'Yes' : 'No' }}</span></td>
                  <td><span class="badge {{ $row->notify_when_available ? 'bg-success' : 'bg-secondary' }}">{{ $row->notify_when_available ? 'Yes' : 'No' }}</span></td>
                  <td>{{ optional($row->created_at)->format('d M Y, H:i') }}</td>
                  <td class="text-end">
                    <form method="post" action="{{ route('admin.reports.host-followers.destroy', $row) }}">
                      @csrf
                      @method('DELETE')
                      <button class="btn btn-sm btn-danger"><i class="ti ti-user-minus me-1"></i>Remove</button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No follower relationships found.</td></tr>
              @endforelse
            </tbody>
          </table>
          <div class="d-flex justify-content-end">{{ $rows->links() }}</div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
