@extends('layouts.admin-berry')
@section('title','Entry Packs')

@section('content')
<div class="admin-section-stack">
  <div class="row g-3">
    <div class="col-md-3">
      <div class="card"><div class="card-body"><div class="text-muted small">Ownership Records</div><div class="h3 mb-0">{{ number_format($report['ownerships'] ?? 0) }}</div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body"><div class="text-muted small">Paid Purchases</div><div class="h3 mb-0">{{ number_format($report['paid_purchases'] ?? 0) }}</div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body"><div class="text-muted small">Wheel Grants</div><div class="h3 mb-0">{{ number_format($report['wheel_grants'] ?? 0) }}</div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card"><div class="card-body d-flex justify-content-between align-items-center"><div><div class="text-muted small">Reports</div><div class="fw-semibold">Usage and purchases</div></div><a class="btn btn-light border" href="{{ route('admin.entry-packs.reports') }}">Open</a></div></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h5 class="mb-0"><i class="ti ti-sparkles me-2"></i>Entry Packs</h5>
      <div class="d-flex gap-2">
        <form method="get" class="d-flex gap-2">
          <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search name">
          <select name="active" class="form-select">
            <option value="">Any</option>
            <option value="1" @selected(request('active') === '1')>Active</option>
            <option value="0" @selected(request('active') === '0')>Inactive</option>
          </select>
          <button class="btn btn-light border">Filter</button>
        </form>
        <a class="btn btn-primary" href="{{ route('admin.entry-packs.create') }}"><i class="ti ti-plus me-1"></i>New Pack</a>
      </div>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Name</th><th>Coins</th><th>Style</th><th>Priority</th><th>FX</th><th>Validity</th><th>Status</th><th>Sort</th><th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
        @forelse($packs as $pack)
          <tr>
            <td>{{ $pack->id }}</td>
            <td>
              <div class="fw-semibold">{{ $pack->name }}</div>
              <div class="small text-muted text-truncate" style="max-width:320px;">{{ $pack->svg_url ?: 'No SVG URL' }}</div>
            </td>
            <td>{{ number_format($pack->price_coins) }}</td>
            <td><span class="badge bg-light text-dark">{{ strtoupper($pack->animation_style) }}</span></td>
            <td>{{ $pack->priority }}</td>
            <td>{{ $pack->duration_ms }}ms</td>
            <td>{{ $pack->duration_days }} days</td>
            <td>{!! $pack->is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
            <td>{{ $pack->sort_order }}</td>
            <td class="text-end">
              <a class="btn btn-sm btn-light border" href="{{ route('admin.entry-packs.edit', $pack) }}">Edit</a>
              <form method="post" action="{{ route('admin.entry-packs.destroy', $pack) }}" class="d-inline" onsubmit="return confirm('Delete this entry pack?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="10" class="text-center text-muted py-4">No entry packs.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-end">{{ $packs->withQueryString()->links() }}</div>
  </div>
</div>
@endsection
