@extends('layouts.admin-berry')
@section('title','New Banner')

@section('content')
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-photo me-2"></i>Create Banner</h6>
      </div>
      <div class="card-body">

        @if($errors->any())
          <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please fix the following:</div>
            <ul class="mb-0 ps-3">
              @foreach($errors->all() as $e)
                <li>{{ $e }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <form method="post" action="{{ route('admin.banners.store') }}" enctype="multipart/form-data" class="vstack gap-3">
          @csrf

          <div>
            <label class="form-label">Title</label>
            <input name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required maxlength="120">
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div>
            <label class="form-label">Upload Image</label>
            <input type="file" name="image_file" accept="image/*" class="form-control @error('image_file') is-invalid @enderror">
            @error('image_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <small class="text-muted d-block mt-1">JPG/PNG/WEBP up to 4MB.</small>
          </div>

          <div>
            <label class="form-label">Image URL (optional fallback)</label>
            <input name="image_url" class="form-control @error('image_url') is-invalid @enderror" value="{{ old('image_url') }}" placeholder="https://..." maxlength="2048">
            @error('image_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div>
            <label class="form-label">Target URL (optional)</label>
            <input name="target_url" class="form-control @error('target_url') is-invalid @enderror" value="{{ old('target_url') }}" placeholder="https://...">
            @error('target_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Placement</label>
              <select name="placement" class="form-select @error('placement') is-invalid @enderror" required>
                @foreach($placements as $placement)
                  <option value="{{ $placement }}" @selected(old('placement', 'home')===$placement)>{{ ucfirst($placement) }}</option>
                @endforeach
              </select>
              @error('placement') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
              <label class="form-label">Action Type</label>
              <select name="action_type" class="form-select @error('action_type') is-invalid @enderror" required>
                @foreach($actionTypes as $actionType)
                  <option value="{{ $actionType }}" @selected(old('action_type', 'none')===$actionType)>{{ strtoupper($actionType) }}</option>
                @endforeach
              </select>
              @error('action_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
          </div>

          <div class="row g-2">
            <div class="col-md-8">
              <label class="form-label">Action Value</label>
              <input name="action_value" class="form-control @error('action_value') is-invalid @enderror" value="{{ old('action_value') }}" placeholder="URL / Deep link / Route">
              @error('action_value') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Button Text</label>
              <input name="button_text" class="form-control @error('button_text') is-invalid @enderror" value="{{ old('button_text') }}" maxlength="60" placeholder="Open">
              @error('button_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
          </div>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label d-block">Platforms</label>
              @foreach($platforms as $platform)
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" id="platform_{{ $platform }}" name="platforms[]" value="{{ $platform }}" @checked(in_array($platform, old('platforms', []), true))>
                  <label class="form-check-label" for="platform_{{ $platform }}">{{ strtoupper($platform) }}</label>
                </div>
              @endforeach
              @error('platforms') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
              @error('platforms.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
              <label class="form-label d-block">Target Roles</label>
              @foreach($roles as $role)
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" id="role_{{ $role }}" name="target_roles[]" value="{{ $role }}" @checked(in_array($role, old('target_roles', []), true))>
                  <label class="form-check-label" for="role_{{ $role }}">{{ ucfirst($role) }}</label>
                </div>
              @endforeach
              @error('target_roles') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
              @error('target_roles.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>
          </div>

          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Sort Order</label>
              <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', 0) }}">
              @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Starts At</label>
              <input type="datetime-local" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at') }}">
              @error('starts_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Ends At</label>
              <input type="datetime-local" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at') }}">
              @error('ends_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
          </div>

          <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }}>
            <label for="is_active" class="form-check-label">Active</label>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="ti ti-check me-1"></i>Save</button>
            <a class="btn btn-light border" href="{{ route('admin.banners.index') }}">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
