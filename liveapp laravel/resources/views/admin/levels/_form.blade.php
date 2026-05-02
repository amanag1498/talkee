@php
  $benefitsValue = old('benefits');
  if ($benefitsValue === null) {
      $benefitsValue = collect($level->benefits ?? [])->implode("\n");
  }
@endphp

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="ti ti-layers-linked me-2"></i>{{ $mode === 'create' ? 'Create Level' : 'Edit Level' }}</h6>
    <a href="{{ route('admin.levels.index') }}" class="btn btn-light border">Back</a>
  </div>
  <div class="card-body row g-3">
    <div class="col-md-3">
      <label class="form-label">Level Number</label>
      <input type="number" min="1" name="level" class="form-control @error('level') is-invalid @enderror" value="{{ old('level', $level->level) }}" required>
      @error('level')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-5">
      <label class="form-label">Title</label>
      <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $level->title) }}" required>
      @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
      <label class="form-label">Minimum Spend Coins</label>
      <input type="number" min="0" name="min_spend_coins" class="form-control @error('min_spend_coins') is-invalid @enderror" value="{{ old('min_spend_coins', $level->min_spend_coins) }}" required>
      @error('min_spend_coins')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
      <label class="form-label">Badge Icon</label>
      <input type="text" name="badge_icon" class="form-control @error('badge_icon') is-invalid @enderror" value="{{ old('badge_icon', $level->badge_icon) }}" placeholder="trending_up">
      @error('badge_icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
      <label class="form-label">Badge Color</label>
      <input type="text" name="badge_color" class="form-control @error('badge_color') is-invalid @enderror" value="{{ old('badge_color', $level->badge_color) }}" placeholder="#4BE3C2">
      @error('badge_color')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
      <label class="form-label">Sort Order</label>
      <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $level->sort_order) }}" required>
      @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
      <label class="form-label d-block">Active</label>
      <div class="form-check form-switch mt-2">
        <input type="hidden" name="is_active" value="0">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $level->is_active))>
        <label class="form-check-label">Enabled</label>
      </div>
    </div>

    <div class="col-12">
      <label class="form-label">Benefits</label>
      <textarea name="benefits" rows="6" class="form-control @error('benefits') is-invalid @enderror" placeholder="One benefit per line or JSON array">{{ $benefitsValue }}</textarea>
      @error('benefits')<div class="invalid-feedback">{{ $message }}</div>@enderror
      <small class="text-muted">Accepted format: one benefit per line, or a JSON array of strings.</small>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end gap-2">
    <a href="{{ route('admin.levels.index') }}" class="btn btn-light border">Cancel</a>
    <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ $mode === 'create' ? 'Create Level' : 'Save Changes' }}</button>
  </div>
</div>
