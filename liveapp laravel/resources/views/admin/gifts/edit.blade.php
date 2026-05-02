@extends('layouts.admin-berry')
@section('title','Edit Gift')

@section('content')
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-edit me-2"></i>Edit Gift</h6>
      </div>
      <div class="card-body">

        {{-- Validation errors --}}
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

        <form method="post" action="{{ route('admin.gifts.update',$gift) }}" class="vstack gap-3">
          @csrf @method('PUT')

          <div>
            <label class="form-label">Name</label>
            <input
              name="name"
              class="form-control @error('name') is-invalid @enderror"
              required
              maxlength="120"
              value="{{ old('name', $gift->name) }}"
            >
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div>
            <label class="form-label">Coins</label>
            <input
              type="number"
              name="coins"
              min="1"
              class="form-control @error('coins') is-invalid @enderror"
              required
              value="{{ old('coins', $gift->coins) }}"
            >
            @error('coins') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div>
            <label class="form-label">Gift URL (image/lottie)</label>
            <input
              type="url"
              name="gift_url"
              class="form-control @error('gift_url') is-invalid @enderror"
              value="{{ old('gift_url', $gift->gift_url) }}"
              placeholder="https://..."
            >
            @error('gift_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <small class="text-muted d-block mt-1">
              Use a full URL like <code>https://...</code>. If you want to allow <code>/storage/...</code> paths,
              change the validation rule to <code>string</code> in the controller.
            </small>
          </div>

          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Gift Type</label>
              <select name="gift_type" class="form-select @error('gift_type') is-invalid @enderror">
                <option value="">Auto detect</option>
                @foreach($giftTypes as $giftType)
                  <option value="{{ $giftType }}" @selected(old('gift_type', $gift->gift_type) === $giftType)>{{ strtoupper($giftType) }}</option>
                @endforeach
              </select>
              @error('gift_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Animation Tier</label>
              <select name="animation_tier" class="form-select @error('animation_tier') is-invalid @enderror">
                <option value="">Auto by coins</option>
                @foreach($animationTiers as $animationTier)
                  <option value="{{ $animationTier }}" @selected(old('animation_tier', $gift->animation_tier) === $animationTier)>{{ ucfirst($animationTier) }}</option>
                @endforeach
              </select>
              @error('animation_tier') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Duration (ms)</label>
              <input
                type="number"
                name="animation_duration_ms"
                min="800"
                max="12000"
                step="100"
                class="form-control @error('animation_duration_ms') is-invalid @enderror"
                value="{{ old('animation_duration_ms', $gift->animation_duration_ms) }}"
                placeholder="optional"
              >
              @error('animation_duration_ms') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Sort Order</label>
              <input
                type="number"
                name="sort_order"
                min="0"
                class="form-control @error('sort_order') is-invalid @enderror"
                value="{{ old('sort_order', $gift->sort_order) }}"
              >
              @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                {{-- Hidden fallback so unchecked submits 0 --}}
                <input type="hidden" name="is_active" value="0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  id="is_active"
                  name="is_active"
                  value="1"
                  {{ old('is_active', (int)$gift->is_active) ? 'checked' : '' }}
                >
                <label for="is_active" class="form-check-label">Active</label>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary">
              <i class="ti ti-check me-1"></i>Update
            </button>
            <a class="btn btn-light border" href="{{ route('admin.gifts.index') }}">
              Back
            </a>
          </div>
        </form>

      </div>
    </div>
  </div>

  @if($gift->gift_url)
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-eye me-2"></i>Preview</h6>
      </div>
      <div class="card-body text-center">
        <img src="{{ $gift->gift_url }}" alt="gift" style="max-height:120px" onerror="this.style.display='none'">
      </div>
    </div>
  </div>
  @endif
</div>
@endsection
