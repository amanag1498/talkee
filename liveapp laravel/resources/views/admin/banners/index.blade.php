@extends('layouts.admin-berry')
@section('title','Banners')

@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-photo me-2"></i>Banners</h5>
    <div class="d-flex gap-2">
      <form method="get" class="d-flex gap-2">
        <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search title">
        <input type="date" class="form-control" name="from" value="{{ request('from', $performance['from']) }}">
        <input type="date" class="form-control" name="to" value="{{ request('to', $performance['to']) }}">
        <select name="placement" class="form-select">
          <option value="">Any placement</option>
          @foreach($placements as $placement)
            <option value="{{ $placement }}" @selected(request('placement')===$placement)>{{ ucfirst($placement) }}</option>
          @endforeach
        </select>
        <select name="active" class="form-select">
          <option value="">Any</option>
          <option value="1" @selected(request('active')==='1')>Active</option>
          <option value="0" @selected(request('active')==='0')>Inactive</option>
        </select>
        <button class="btn btn-light border">Filter</button>
      </form>
      <a class="btn btn-primary" href="{{ route('admin.banners.create') }}"><i class="ti ti-plus me-1"></i>New Banner</a>
    </div>
  </div>

  <div class="card-body border-bottom">
    <div class="row g-2">
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">Impressions</small>
          <strong>{{ number_format($performance['impressions']) }}</strong>
        </div>
      </div>
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">Clicks</small>
          <strong>{{ number_format($performance['clicks']) }}</strong>
        </div>
      </div>
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">CTR</small>
          <strong>{{ $performance['ctr'] }}%</strong>
        </div>
      </div>
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">Unique Impressions</small>
          <strong>{{ number_format($performance['unique_impressions']) }}</strong>
        </div>
      </div>
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">Unique CTR</small>
          <strong>{{ $performance['unique_ctr'] }}%</strong>
        </div>
      </div>
      <div class="col-md-2">
        <div class="p-2 bg-light rounded">
          <small class="text-muted d-block">Repeat Impressions</small>
          <strong>{{ number_format($performance['repeat_impressions']) }}</strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Title</th><th>Placement</th><th>Preview</th><th>Action</th><th>Targeting</th><th>Performance</th><th>Status</th><th>Schedule</th><th>Sort</th><th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse($banners as $b)
        @php
          $img = (string) ($b->image_url ?? '');
          $previewUrl = $img === ''
            ? ''
            : (\Illuminate\Support\Str::startsWith($img, ['http://', 'https://', '/']) ? $img : \Illuminate\Support\Facades\Storage::url($img));
        @endphp
        <tr>
          <td>{{ $b->id }}</td>
          <td class="fw-medium">{{ $b->title }}</td>
          <td><span class="badge bg-light text-dark border">{{ $b->placement ?: 'home' }}</span></td>
          <td>
            @if($previewUrl !== '')
              <img src="{{ $previewUrl }}" alt="banner" style="height:40px; max-width:120px; object-fit:cover; border-radius:6px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="text-muted" style="display:none;">Image unavailable</span>
            @else
              <span class="text-muted">No image</span>
            @endif
          </td>
          <td>
            <small class="d-block"><strong>{{ strtoupper($b->action_type ?: 'none') }}</strong></small>
            @if($b->action_value)
              <small class="text-muted d-block">{{ \Illuminate\Support\Str::limit($b->action_value, 36) }}</small>
            @elseif($b->target_url)
              <small class="text-muted d-block">{{ \Illuminate\Support\Str::limit($b->target_url, 36) }}</small>
            @endif
            @if($b->button_text)
              <small class="badge bg-light text-dark border mt-1">{{ $b->button_text }}</small>
            @endif
          </td>
          <td>
            <small class="text-muted d-block">Platforms: {{ !empty($b->platforms) ? implode(', ', $b->platforms) : 'All' }}</small>
            <small class="text-muted d-block">Roles: {{ !empty($b->target_roles) ? implode(', ', $b->target_roles) : 'All' }}</small>
          </td>
          <td>
            <small class="text-muted d-block">Imp: {{ number_format((int) $b->impressions_count) }}</small>
            <small class="text-muted d-block">Clk: {{ number_format((int) $b->clicks_count) }}</small>
            <small class="text-muted d-block">Unique Imp: {{ number_format((int) ($b->unique_impressions_count ?? 0)) }}</small>
            <small class="text-muted d-block">Unique Clk: {{ number_format((int) ($b->unique_clicks_count ?? 0)) }}</small>
            <small class="text-muted d-block">CTR:
              {{ (int) $b->impressions_count > 0 ? round(((int) $b->clicks_count * 100) / (int) $b->impressions_count, 2) : 0 }}%
            </small>
            <small class="text-muted d-block">Last Imp: {{ $b->last_impression_at ? \Carbon\Carbon::parse($b->last_impression_at)->diffForHumans() : '—' }}</small>
            <small class="text-muted d-block">Last Click: {{ $b->last_click_at ? \Carbon\Carbon::parse($b->last_click_at)->diffForHumans() : '—' }}</small>
          </td>
          <td>
            @if($b->is_active) <span class="badge bg-success">Active</span>
            @else <span class="badge bg-secondary">Inactive</span>
            @endif
          </td>
          <td>
            <small class="text-muted d-block">Start: {{ $b->starts_at?->format('d M Y H:i') ?? 'Any' }}</small>
            <small class="text-muted d-block">End: {{ $b->ends_at?->format('d M Y H:i') ?? 'Any' }}</small>
          </td>
          <td>{{ $b->sort_order }}</td>
          <td class="text-end">
            <a class="btn btn-sm btn-light border" href="{{ route('admin.banners.edit', $b) }}"><i class="ti ti-edit me-1"></i>Edit</a>
            <form method="post" action="{{ route('admin.banners.destroy', $b) }}" class="d-inline" onsubmit="return confirm('Delete this banner?')">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger"><i class="ti ti-trash me-1"></i>Delete</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="11" class="text-center text-muted py-4">No banners.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex justify-content-end">
    {{ $banners->withQueryString()->links() }}
  </div>
</div>
@endsection
