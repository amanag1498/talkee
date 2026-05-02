@extends('layouts.admin-berry')
@section('title','Hosts')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-user-star me-2"></i>Hosts</h5>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search stage/name/email">
      <button class="btn btn-light border">Search</button>
    </form>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Stage Name</th>
          <th>User</th>
          <th>Phone</th>
          <th>Agency</th>
          <th>Followers</th>
          <th>Payout %</th>
          <th>Weekly Bonus</th>
          <th>Photos</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @foreach($hosts as $h)
        <tr>
          <td>{{ $h->id }}</td>
          <td>{{ $h->stage_name ?: '—' }}</td>
          <td>
            <div class="d-flex flex-column">
              <span class="fw-medium">{{ $h->user?->name ?? '—' }}</span>
              <small class="text-muted">{{ $h->user?->email ?? '' }}</small>
            </div>
          </td>
          <td>{{ $h->contact_phone ?: '—' }}</td>
          <td>{{ $h->agency?->name ?: '—' }}</td>
          <td><span class="badge bg-primary">{{ $h->followers_count ?? 0 }}</span></td>
          <td>{{ number_format((float)$h->payout_percentage, 2) }}%</td>
          <td>{{ $h->weekly_bonus ? number_format($h->weekly_bonus) : '0' }}</td>
          <td>
            @php $count = $h->relationLoaded('photos') ? $h->photos->count() : ($h->photos_count ?? 0); @endphp
            <span class="badge bg-secondary">{{ $count }}</span>
          </td>
          <td>
            @if($h->is_blocked)
              <span class="badge bg-danger">Blocked</span>
            @else
              <span class="badge bg-success">Active</span>
            @endif
          </td>
          <td class="text-end">
            <a href="{{ route('admin.hosts.edit',$h) }}" class="btn btn-sm btn-primary me-2">
              <i class="ti ti-edit me-1"></i>Edit
            </a>
            @if($h->is_blocked)
              <form method="post" action="{{ route('admin.hosts.unblock',$h) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-success"><i class="ti ti-lock-open me-1"></i>Unblock</button>
              </form>
            @else
              <form method="post" action="{{ route('admin.hosts.block',$h) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-danger"><i class="ti ti-lock me-1"></i>Block</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <div class="d-flex justify-content-end">{{ $hosts->links() }}</div>
  </div>
</div>
@endsection
