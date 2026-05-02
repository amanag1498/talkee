@extends('layouts.admin-berry')

@section('title', 'Theme Details')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <span class="admin-page-eyebrow"><i class="ti ti-badge"></i> Theme Access</span>
      <h1 class="admin-page-title">{{ $theme->name }}</h1>
      <p class="admin-page-subtitle">{{ $theme->description ?: 'No description set.' }}</p>
      <div class="mt-2 text-muted small">
        <span class="me-3">Key: <code>{{ $theme->key }}</code></span>
        <span class="me-3">Token Source: <strong>{{ $theme->token_source ?? 'local' }}</strong></span>
        <span>App Window: {{ $theme->min_app_version ?? 'any' }} → {{ $theme->max_app_version ?? 'any' }}</span>
      </div>
    </section>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Grant Theme</h5>
        <a href="{{ route('admin.themes.edit', $theme) }}" class="btn btn-sm btn-primary">Edit Theme</a>
      </div>
      <div class="card-body">
        <form method="post" action="{{ route('admin.themes.grant', $theme) }}" class="row g-3">
          @csrf
          <div class="col-md-3">
            <label class="form-label">User ID</label>
            <input type="number" min="1" name="user_id" class="form-control @error('user_id') is-invalid @enderror" value="{{ old('user_id') }}">
            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Source</label>
            <input name="source" class="form-control @error('source') is-invalid @enderror" value="{{ old('source', 'admin_grant') }}">
            @error('source')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Expires At</label>
            <input type="datetime-local" name="expires_at" class="form-control @error('expires_at') is-invalid @enderror" value="{{ old('expires_at') }}">
            @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Metadata JSON</label>
            <input name="metadata_json" class="form-control @error('metadata_json') is-invalid @enderror" value="{{ old('metadata_json') }}">
            @error('metadata_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-12">
            <button class="btn btn-primary">Grant Theme</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="mb-0">Unlocked Users</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr>
                <th>User</th>
                <th>Source</th>
                <th>Granted By</th>
                <th>Expires</th>
                <th>Granted At</th>
                <th class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              @forelse($users as $unlock)
                <tr>
                  <td>
                    <div class="fw-semibold">{{ $unlock->user?->name }}</div>
                    <div class="text-muted small">#{{ $unlock->user_id }} · {{ $unlock->user?->email }}</div>
                  </td>
                  <td><span class="badge bg-light text-dark">{{ $unlock->source }}</span></td>
                  <td>{{ $unlock->grantedBy?->name ?? 'System' }}</td>
                  <td>{{ optional($unlock->expires_at)?->format('Y-m-d H:i') ?? 'Permanent' }}</td>
                  <td>{{ optional($unlock->created_at)?->format('Y-m-d H:i') }}</td>
                  <td class="text-end">
                    @if($theme->key !== 'midnight')
                      <form method="post" action="{{ route('admin.themes.revoke', [$theme, $unlock->user_id]) }}" class="d-inline">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke this theme?')">Revoke</button>
                      </form>
                    @endif
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">No unlocks yet.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
@endsection
