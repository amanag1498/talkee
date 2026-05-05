@extends('layouts.admin-berry')
@section('title','Profile Frames')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-photo-star me-2"></i>Profile Frames</h5>
    <div class="d-flex gap-2">
      <form method="get" class="d-flex gap-2">
        <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search name or slug">
        <select name="active" class="form-select">
          <option value="">Any</option>
          <option value="1" @selected(request('active')==='1')>Active</option>
          <option value="0" @selected(request('active')==='0')>Inactive</option>
        </select>
        <button class="btn btn-light border">Filter</button>
      </form>
      <form method="post" action="{{ route('admin.profile-frames.awards.run') }}" onsubmit="return confirm('Run the 7-day weekly/all-time profile frame awards now?')">
        @csrf
        <button class="btn btn-warning">
          <i class="ti ti-trophy me-1"></i>Run Monday Awards
        </button>
      </form>
      <a class="btn btn-primary" href="{{ route('admin.profile-frames.create') }}"><i class="ti ti-plus me-1"></i>New Frame</a>
    </div>
  </div>
  @if(session('frame_awards_report'))
    @php($report = session('frame_awards_report'))
    <div class="card-body border-bottom bg-light-subtle">
      <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
        <span class="badge bg-primary">Granted {{ $report['granted_count'] ?? 0 }}</span>
        <span class="badge bg-secondary">Skipped {{ $report['skipped_count'] ?? 0 }}</span>
        <span class="text-muted small">Last week: {{ $report['week_start'] ?? '—' }} to {{ $report['week_end'] ?? '—' }}</span>
        <span class="text-muted small">Duration: {{ $report['reward_days'] ?? 7 }} days</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Reward</th>
              <th>Winner</th>
              <th>Frame</th>
              <th>Status</th>
              <th>Expires</th>
            </tr>
          </thead>
          <tbody>
          @foreach(($report['rows'] ?? []) as $row)
            <tr>
              <td>{{ $row['title'] ?? '—' }}</td>
              <td>{{ $row['winner_name'] ?? 'No winner' }}</td>
              <td><code>{{ $row['frame_slug'] ?? '—' }}</code></td>
              <td>{{ $row['status'] ?? '—' }}</td>
              <td>{{ $row['expires_at'] ? \Illuminate\Support\Carbon::parse($row['expires_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i A') : '—' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Frame</th>
          <th>Preview</th>
          <th>Rarity</th>
          <th>Category</th>
          <th>Unlock</th>
          <th>Validity</th>
          <th>Status</th>
          <th>Sort</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($frames as $frame)
        <tr>
          <td>{{ $frame->id }}</td>
          <td>
            <div class="fw-semibold">{{ $frame->name }}</div>
            <div class="text-muted small">{{ $frame->slug }}</div>
          </td>
          <td>
            @if($frame->thumbnail_url || $frame->asset_url)
              <img src="{{ $frame->thumbnail_url ?: $frame->asset_url }}" alt="frame" style="width:68px;height:68px;object-fit:contain;">
            @else
              <span class="text-muted">—</span>
            @endif
          </td>
          <td><span class="badge bg-light text-dark">{{ ucfirst($frame->rarity) }}</span></td>
          <td>{{ $frame->category ?: 'general' }}</td>
          <td>{{ $frame->unlock_type ?: 'free_catalog' }}</td>
          <td>{{ $frame->valid_days ? number_format($frame->valid_days).' days' : 'Permanent' }}</td>
          <td>
            @if($frame->is_active)
              <span class="badge bg-success">Active</span>
            @else
              <span class="badge bg-secondary">Inactive</span>
            @endif
          </td>
          <td>{{ $frame->sort_order }}</td>
          <td class="text-end">
            <a class="btn btn-sm btn-light border" href="{{ route('admin.profile-frames.edit',$frame) }}"><i class="ti ti-edit me-1"></i>Edit</a>
            <form method="post" action="{{ route('admin.profile-frames.destroy',$frame) }}" class="d-inline" onsubmit="return confirm('Delete this profile frame?')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger"><i class="ti ti-trash me-1"></i>Delete</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="10" class="text-center text-muted py-4">No profile frames.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-end">
    {{ $frames->withQueryString()->links() }}
  </div>
</div>
@endsection
