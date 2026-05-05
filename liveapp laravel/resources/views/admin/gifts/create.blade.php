@extends('layouts.admin-berry')
@section('title','New Gift')

@section('content')
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-gift me-2"></i>Create Gift</h6>
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

        <form method="post" action="{{ route('admin.gifts.store') }}" class="vstack gap-3" enctype="multipart/form-data">
          @csrf

          <div>
            <label class="form-label">Name</label>
            <input
              name="name"
              class="form-control @error('name') is-invalid @enderror"
              value="{{ old('name') }}"
              required
              maxlength="120"
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
              value="{{ old('coins') }}"
              required
            >
            @error('coins') <div class="invalid-feedback">{{ $message }}</div> @enderror
          </div>

          <div>
            <label class="form-label">Gift Asset</label>
            <input
              type="file"
              name="gift_file"
              id="gift_file"
              accept=".svg,.svga,.gif,.png,.jpg,.jpeg,.webp"
              class="form-control @error('gift_file') is-invalid @enderror"
              required
            >
            @error('gift_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
            <small class="text-muted d-block mt-1">
              Upload SVG, SVGA, GIF, PNG, JPG, JPEG, or WEBP.
            </small>
          </div>

          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">Gift Type</label>
              <select name="gift_type" class="form-select @error('gift_type') is-invalid @enderror">
                <option value="">Auto detect</option>
                @foreach($giftTypes as $giftType)
                  <option value="{{ $giftType }}" @selected(old('gift_type') === $giftType)>{{ strtoupper($giftType) }}</option>
                @endforeach
              </select>
              @error('gift_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-4">
              <label class="form-label">Animation Tier</label>
              <select name="animation_tier" class="form-select @error('animation_tier') is-invalid @enderror">
                <option value="">Auto by coins</option>
                @foreach($animationTiers as $animationTier)
                  <option value="{{ $animationTier }}" @selected(old('animation_tier') === $animationTier)>{{ ucfirst($animationTier) }}</option>
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
                value="{{ old('animation_duration_ms') }}"
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
                value="{{ old('sort_order', 0) }}"
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
                  {{ old('is_active', 1) ? 'checked' : '' }}
                >
                <label for="is_active" class="form-check-label">Active</label>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary">
              <i class="ti ti-check me-1"></i>Save
            </button>
            <a class="btn btn-light border" href="{{ route('admin.gifts.index') }}">
              Cancel
            </a>
          </div>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-eye me-2"></i>Preview</h6>
      </div>
      <div class="card-body">
        <div id="gift-preview-shell"
             class="rounded-4 border d-flex align-items-center justify-content-center overflow-hidden"
             style="min-height: 320px; background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); border-color: rgba(148, 163, 184, 0.24) !important;">
          <div id="gift-preview-empty" class="text-center text-muted px-4">
            <div class="mb-2"><i class="ti ti-photo-search" style="font-size: 2rem;"></i></div>
            <div class="fw-semibold">Upload a gift asset to preview it</div>
            <div class="small mt-1">SVG, SVGA, GIF, PNG, JPG, JPEG, and WEBP are supported.</div>
          </div>
          <img id="gift-preview-image" alt="gift preview" class="d-none" style="max-width: 100%; max-height: 280px; object-fit: contain;">
          <object id="gift-preview-svg" type="image/svg+xml" class="d-none" style="width: 100%; height: 280px;"></object>
          <canvas id="gift-preview-svga" class="d-none" style="width: 100%; height: 280px;"></canvas>
        </div>
        <div id="gift-preview-meta" class="small text-muted mt-3 d-none"></div>
      </div>
    </div>
  </div>
</div>

@once
  <script src="https://cdn.jsdelivr.net/npm/svgaplayerweb@2.3.0/build/svga.min.js"></script>
@endonce
<script>
  (() => {
    const fileInput = document.getElementById('gift_file');
    const empty = document.getElementById('gift-preview-empty');
    const image = document.getElementById('gift-preview-image');
    const svg = document.getElementById('gift-preview-svg');
    const canvas = document.getElementById('gift-preview-svga');
    const meta = document.getElementById('gift-preview-meta');
    let activeObjectUrl = null;
    let activeSvgaPlayer = null;

    const releaseObjectUrl = () => {
      if (activeObjectUrl) {
        URL.revokeObjectURL(activeObjectUrl);
        activeObjectUrl = null;
      }
    };

    const reset = () => {
      if (activeSvgaPlayer && typeof activeSvgaPlayer.clear === 'function') {
        try { activeSvgaPlayer.clear(); } catch (_) {}
      }
      activeSvgaPlayer = null;
      empty.classList.remove('d-none');
      image.classList.add('d-none');
      svg.classList.add('d-none');
      canvas.classList.add('d-none');
      image.removeAttribute('src');
      svg.removeAttribute('data');
      meta.classList.add('d-none');
      meta.textContent = '';
    };

    const showMeta = (text) => {
      meta.textContent = text;
      meta.classList.remove('d-none');
    };

    const showImage = (url, text) => {
      reset();
      empty.classList.add('d-none');
      image.src = url;
      image.classList.remove('d-none');
      showMeta(text);
    };

    const showSvg = (url, text) => {
      reset();
      empty.classList.add('d-none');
      svg.data = url;
      svg.classList.remove('d-none');
      showMeta(text);
    };

    const showSvga = (url, text) => {
      reset();
      empty.classList.add('d-none');
      canvas.classList.remove('d-none');
      showMeta(text);

      if (!window.SVGA || !window.SVGA.Player || !window.SVGA.Parser) {
        meta.textContent = 'SVGA preview library did not load.';
        return;
      }

      activeSvgaPlayer = new window.SVGA.Player(canvas);
      const parser = new window.SVGA.Parser(canvas);
      parser.load(
        url,
        (videoItem) => {
          activeSvgaPlayer.setVideoItem(videoItem);
          activeSvgaPlayer.startAnimation();
        },
        () => {
          meta.textContent = 'Unable to load this SVGA preview.';
        }
      );
    };

    fileInput?.addEventListener('change', (event) => {
      const file = event.target.files?.[0];
      if (!file) {
        releaseObjectUrl();
        reset();
        return;
      }

      releaseObjectUrl();
      const extension = (file.name.split('.').pop() || '').toLowerCase();
      activeObjectUrl = URL.createObjectURL(file);
      const sizeLabel = `${(file.size / 1024).toFixed(1)} KB`;
      const metaText = `${file.name} • ${sizeLabel} • ${extension.toUpperCase() || 'FILE'}`;

      if (extension === 'svga') {
        showSvga(activeObjectUrl, metaText);
      } else if (extension === 'svg') {
        showSvg(activeObjectUrl, metaText);
      } else {
        showImage(activeObjectUrl, metaText);
      }
    });

    reset();
  })();
</script>
@endsection
