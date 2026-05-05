<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-sparkles me-2"></i>{{ $pack ? 'Edit Entry Pack' : 'Create Entry Pack' }}</h6>
      </div>
      <div class="card-body">
        @if($errors->any())
          <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please fix the following:</div>
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
          </div>
        @endif
        <form method="post" action="{{ $route }}" class="vstack gap-3" enctype="multipart/form-data">
          @csrf
          @if($method !== 'POST') @method($method) @endif
          <div>
            <label class="form-label">Name</label>
            <input name="name" class="form-control" value="{{ old('name', $pack?->name) }}" required maxlength="120">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Price Coins</label>
              <input type="number" min="1" name="price_coins" class="form-control" value="{{ old('price_coins', $pack?->price_coins ?? 150) }}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Animation Style</label>
              <select name="animation_style" class="form-select">
                @foreach(['banner','center','fullscreen'] as $style)
                  <option value="{{ $style }}" @selected(old('animation_style', $pack?->animation_style ?? 'banner') === $style)>{{ ucfirst($style) }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div>
            <label class="form-label">{{ $pack ? 'Replace Entry Asset' : 'Entry Asset' }}</label>
            <input
              type="file"
              name="asset_file"
              accept=".svg,.svga"
              class="form-control @error('asset_file') is-invalid @enderror"
              {{ $pack ? '' : 'required' }}
            >
            @error('asset_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <small class="text-muted d-block mt-1">
              Upload SVG or SVGA. Leave empty while editing to keep the current file.
            </small>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Priority</label>
              <input type="number" min="1" name="priority" class="form-control" value="{{ old('priority', $pack?->priority ?? 1) }}">
            </div>
            <div class="col-md-4">
              <label class="form-label">Duration (ms)</label>
              <input type="number" min="2000" max="4000" step="100" name="duration_ms" class="form-control" value="{{ old('duration_ms', $pack?->duration_ms ?? 3000) }}">
            </div>
            <div class="col-md-4">
              <label class="form-label">Validity (days)</label>
              <input type="number" min="1" max="3650" name="duration_days" class="form-control" value="{{ old('duration_days', $pack?->duration_days ?? 30) }}">
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Sort Order</label>
              <input type="number" min="0" name="sort_order" class="form-control" value="{{ old('sort_order', $pack?->sort_order ?? 0) }}">
            </div>
          </div>
          <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $pack ? (int)$pack->is_active : 1) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary">{{ $pack ? 'Update' : 'Save' }}</button>
            <a class="btn btn-light border" href="{{ route('admin.entry-packs.index') }}">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="ti ti-eye me-2"></i>Preview</h6></div>
      <div class="card-body">
        @php($previewAsset = old('svg_url', $pack?->svg_url))
        @if($previewAsset)
          @if(str_ends_with(strtolower($previewAsset), '.svga'))
            <div class="text-muted">SVGA asset uploaded. Preview is available in the app runtime.</div>
          @else
            <object data="{{ $previewAsset }}" type="image/svg+xml" style="width:100%;height:220px;border-radius:16px;background:#f8fafc;"></object>
          @endif
        @else
          <div class="text-muted">Upload an SVG or SVGA file to preview the entry artwork.</div>
        @endif
      </div>
    </div>
  </div>
</div>
