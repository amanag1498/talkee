@php
  $isCreate = !isset($frame) || !$frame->exists;
@endphp

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <h5 class="mb-0">{{ $isCreate ? 'Create Profile Frame' : 'Edit Profile Frame' }}</h5>
      <div class="text-muted small">PNG or WebP overlays rendered above user avatars in the app.</div>
    </div>
    <a href="{{ route('admin.profile-frames.index') }}" class="btn btn-light border">Back</a>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $frame->name) }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <div class="col-md-6">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $frame->slug) }}" placeholder="auto-from-name">
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>

      <div class="col-md-6">
        <label class="form-label">Frame Asset</label>
        <input type="file" name="asset" accept=".png,.webp" class="form-control @error('asset') is-invalid @enderror" {{ $isCreate ? 'required' : '' }}>
        @error('asset')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @if($frame->asset_url)
          <div class="mt-2">
            <img src="{{ $frame->asset_url }}" alt="frame asset" style="max-width:180px; max-height:180px;">
          </div>
        @endif
      </div>

      <div class="col-md-6">
        <label class="form-label">Thumbnail Asset</label>
        <input type="file" name="thumbnail" accept=".png,.webp" class="form-control @error('thumbnail') is-invalid @enderror">
        @error('thumbnail')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Optional. If omitted, the main frame asset is reused.</div>
        @if($frame->thumbnail_url)
          <div class="mt-2">
            <img src="{{ $frame->thumbnail_url }}" alt="frame thumbnail" style="max-width:120px; max-height:120px;">
          </div>
        @endif
      </div>

      <div class="col-md-4">
        <label class="form-label">Rarity</label>
        <select name="rarity" class="form-select @error('rarity') is-invalid @enderror">
          @foreach(['common','rare','epic','legendary','mythic'] as $rarity)
            <option value="{{ $rarity }}" @selected(old('rarity', $frame->rarity) === $rarity)>{{ ucfirst($rarity) }}</option>
          @endforeach
        </select>
        @error('rarity')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>

      <div class="col-md-4">
        <label class="form-label">Category</label>
        <input type="text" name="category" class="form-control @error('category') is-invalid @enderror" value="{{ old('category', $frame->category) }}" placeholder="general">
        @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>

      <div class="col-md-4">
        <label class="form-label">Unlock Type</label>
        <select name="unlock_type" class="form-select @error('unlock_type') is-invalid @enderror">
          @foreach(['free_catalog','level_reward','weekly_top_gifter','weekly_top_host','weekly_top_agency','alltime_top_gifter','alltime_top_host','alltime_top_agency','monthly_top_gifter','vip_perk','event_reward','host_reward','agency_reward','admin_grant','shop_purchase'] as $unlockType)
            <option value="{{ $unlockType }}" @selected(old('unlock_type', $frame->unlock_type) === $unlockType)>{{ $unlockType }}</option>
          @endforeach
        </select>
        @error('unlock_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>

      <div class="col-md-4">
        <label class="form-label">Valid Days</label>
        <input type="number" min="1" max="3650" name="valid_days" class="form-control @error('valid_days') is-invalid @enderror" value="{{ old('valid_days', $frame->valid_days) }}">
        @error('valid_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Leave empty for permanent.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Price Coins</label>
        <input type="number" min="0" max="100000000" name="price_coins" class="form-control @error('price_coins') is-invalid @enderror" value="{{ old('price_coins', $frame->price_coins) }}">
        @error('price_coins')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Used when unlock type is <code>shop_purchase</code>.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Sort Order</label>
        <input type="number" min="0" max="9999" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $frame->sort_order ?? 0) }}">
        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>

      <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $frame->is_active ?? true))>
          <label class="form-check-label" for="is_active">Active</label>
        </div>
      </div>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end gap-2">
    <a href="{{ route('admin.profile-frames.index') }}" class="btn btn-light border">Cancel</a>
    <button class="btn btn-primary">{{ $isCreate ? 'Create Frame' : 'Save Changes' }}</button>
  </div>
</div>
