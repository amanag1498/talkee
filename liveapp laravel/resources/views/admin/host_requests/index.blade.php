@extends('layouts.admin-berry')
@section('title','Host Requests')

@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-user-star me-2"></i>Host Requests</h5>
    <span class="text-muted small">Total: {{ $requests->total() }}</span>
  </div>

  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>User</th>
          <th>Stage Name</th>
          <th>Status</th>
          <th>Applied</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($requests as $r)
        <tr>
          <td>{{ $r->id }}</td>
          <td>
            <div class="d-flex flex-column">
              <span class="fw-medium">{{ $r->user?->name ?? '—' }}</span>
              <small class="text-muted">{{ $r->user?->email ?? '' }}</small>
            </div>
          </td>
          <td>{{ $r->stage_name ?: '—' }}</td>
          <td>
            <span class="badge rounded-pill {{ $r->status==='pending' ? 'bg-warning text-dark' : ($r->status==='approved' ? 'bg-success' : 'bg-danger') }}">
              {{ ucfirst($r->status) }}
            </span>
          </td>
          <td>{{ $r->created_at?->format('d M Y') }}</td>
          <td class="text-end">
            <a class="btn btn-sm btn-primary" href="{{ route('admin.host-requests.show',$r) }}">
              <i class="ti ti-eye me-1"></i> Review
            </a>
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="text-center text-muted py-4">No host requests.</td></tr>
      @endforelse
      </tbody>
    </table>

    <div class="d-flex justify-content-end">{{ $requests->links() }}</div>
  </div>
</div>
@endsection
