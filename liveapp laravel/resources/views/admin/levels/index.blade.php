@extends('layouts.admin-berry')
@section('title', 'Levels')

@section('content')
<div class="admin-page-shell">
  <section class="admin-page-hero">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-layers-linked"></i>Level Management</span>
        <h1 class="admin-page-title">User Levels</h1>
        <p class="admin-page-subtitle">Create, update, activate, sort, and maintain the `user_levels` configuration used by the spend-based level engine.</p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a href="{{ route('admin.reports.levels') }}" class="btn btn-light border">Open Level Report</a>
          <a href="{{ route('admin.levels.create') }}" class="btn btn-primary">Create Level</a>
        </div>
      </div>
    </div>
  </section>

  @if(session('ok'))
    <div class="alert alert-success">{{ session('ok') }}</div>
  @endif
  @if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
  @endif

  <div class="row g-3">
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Configured Levels</div><div class="h3 mb-0">{{ number_format($summary['levels'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Active Levels</div><div class="h3 mb-0">{{ number_format($summary['active_levels'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Users Mapped</div><div class="h3 mb-0">{{ number_format($summary['users_mapped'] ?? 0) }}</div></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Top Threshold</div><div class="h3 mb-0">{{ number_format($summary['highest_threshold'] ?? 0) }}</div></div></div></div>
  </div>

  <section class="card">
    <div class="card-header"><h5 class="mb-0">Configured Levels</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Level</th>
            <th>Title</th>
            <th>Minimum Spend</th>
            <th>Badge</th>
            <th>Benefits</th>
            <th>Status</th>
            <th>Sort</th>
            <th>Users</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($levels as $level)
            <tr>
              <td class="fw-semibold">L{{ $level->level }}</td>
              <td>{{ $level->title }}</td>
              <td>{{ number_format($level->min_spend_coins) }}</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge" style="background: {{ $level->badge_color ?: '#6c757d' }}">{{ $level->badge_icon ?: 'default' }}</span>
                  <small class="text-muted">{{ $level->badge_color ?: '—' }}</small>
                </div>
              </td>
              <td>
                @if(!empty($level->benefits))
                  <div class="small">
                    @foreach(array_slice($level->benefits, 0, 3) as $benefit)
                      <div>{{ $benefit }}</div>
                    @endforeach
                    @if(count($level->benefits) > 3)
                      <div class="text-muted">+{{ count($level->benefits) - 3 }} more</div>
                    @endif
                  </div>
                @else
                  <span class="text-muted">—</span>
                @endif
              </td>
              <td>
                <span class="badge {{ $level->is_active ? 'bg-success' : 'bg-secondary' }}">
                  {{ $level->is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>{{ $level->sort_order }}</td>
              <td>{{ number_format($level->users_count) }}</td>
              <td class="text-end">
                <div class="d-inline-flex gap-2">
                  <a href="{{ route('admin.levels.edit', $level) }}" class="btn btn-sm btn-light border">Edit</a>
                  <form method="post" action="{{ route('admin.levels.destroy', $level) }}" onsubmit="return confirm('Delete this level?');">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger" @disabled($level->users_count > 0)>Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No levels configured.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
