@extends('layouts.admin-berry')

@section('title', 'Themes')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <span class="admin-page-eyebrow"><i class="ti ti-palette"></i> Theme Unlock System</span>
      <h1 class="admin-page-title">Theme Management</h1>
      <p class="admin-page-subtitle">Control unlock rules, local or remote token delivery, limited windows, grants, and default theme fallback without changing app code.</p>
    </section>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Theme Catalog</h5>
        <div class="d-flex align-items-center gap-3">
          <div class="text-muted small">Built-in Flutter themes stay local. Event themes can be delivered remotely.</div>
          <a href="{{ route('admin.themes.create') }}" class="btn btn-sm btn-primary">Create Theme</a>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>Theme</th>
                <th>Unlock Type</th>
                <th>Status</th>
                <th>Token Source</th>
                <th>Limited</th>
                <th>Unlocks</th>
                <th>Sort</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($themes as $theme)
                <tr>
                  <td>
                    <div class="fw-semibold">{{ $theme->name }}</div>
                    <div class="text-muted small">{{ $theme->key }}</div>
                  </td>
                  <td><span class="badge bg-light text-dark">{{ $theme->unlock_type }}</span></td>
                  <td>
                    @if($theme->is_default)
                      <span class="badge bg-success-subtle text-success">Default</span>
                    @elseif($theme->is_active)
                      <span class="badge bg-primary-subtle text-primary">Active</span>
                    @else
                      <span class="badge bg-danger-subtle text-danger">Disabled</span>
                    @endif
                  </td>
                  <td><span class="badge bg-light text-dark">{{ $theme->token_source ?? 'local' }}</span></td>
                  <td>{{ $theme->is_limited ? 'Yes' : 'No' }}</td>
                  <td>{{ $theme->user_unlocks_count }}</td>
                  <td>{{ $theme->sort_order }}</td>
                  <td class="text-end">
                    <a href="{{ route('admin.themes.show', $theme) }}" class="btn btn-sm btn-light border">View</a>
                    <a href="{{ route('admin.themes.edit', $theme) }}" class="btn btn-sm btn-primary">Edit</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
@endsection
