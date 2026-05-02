@extends('layouts.admin-berry')
@section('title','Gifts')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-gift me-2"></i>Gifts</h5>
    <div class="d-flex gap-2">
      <form method="get" class="d-flex gap-2">
        <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search name">
        <select name="active" class="form-select">
          <option value="">Any</option>
          <option value="1" @selected(request('active')==='1')>Active</option>
          <option value="0" @selected(request('active')==='0')>Inactive</option>
        </select>
        <button class="btn btn-light border">Filter</button>
      </form>
      <a class="btn btn-primary" href="{{ route('admin.gifts.create') }}"><i class="ti ti-plus me-1"></i>New Gift</a>
    </div>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Name</th><th>Coins</th><th>Preview</th><th>Type</th><th>Tier</th><th>Duration</th><th>Status</th><th>Sort</th><th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($gifts as $g)
        <tr>
          <td>{{ $g->id }}</td>
          <td class="fw-medium">{{ $g->name }}</td>
          <td class="fw-semibold">{{ number_format($g->coins) }}</td>
          <td>
            @if($g->gift_url)
              <img src="{{ $g->gift_url }}" alt="gift" style="height:36px">
            @else
              <span class="text-muted">—</span>
            @endif
          </td>
          <td>{{ $g->gift_type ?: 'auto' }}</td>
          <td>{{ $g->animation_tier ?: 'auto' }}</td>
          <td>{{ $g->animation_duration_ms ? number_format($g->animation_duration_ms).' ms' : 'auto' }}</td>
          <td>
            @if($g->is_active) <span class="badge bg-success">Active</span>
            @else <span class="badge bg-secondary">Inactive</span>
            @endif
          </td>
          <td>{{ $g->sort_order }}</td>
          <td class="text-end">
            <a class="btn btn-sm btn-light border" href="{{ route('admin.gifts.edit',$g) }}"><i class="ti ti-edit me-1"></i>Edit</a>
            <form method="post" action="{{ route('admin.gifts.destroy',$g) }}" class="d-inline" onsubmit="return confirm('Delete this gift?')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger"><i class="ti ti-trash me-1"></i>Delete</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="10" class="text-center text-muted py-4">No gifts.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-end">
    {{ $gifts->withQueryString()->links() }}
  </div>
</div>
@endsection
