@extends('layouts.admin-berry')

@section('title', 'Call Settings')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-adjustments-horizontal"></i> Global Call Configuration</span>
          <h1 class="admin-page-title">Call Settings</h1>
          <p class="admin-page-subtitle">
            These values are stored in the database and loaded into <code>config('calls')</code> on each request.
            Host-specific rates override the global audio/video rate only when set on the host record.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="card border-0 shadow-none bg-transparent">
            <div class="card-body p-0">
              <div class="d-grid gap-2">
                <div class="badge bg-light text-dark p-3 text-start">
                  Legacy fallback env rate: <strong>{{ number_format($legacyFallbackRate) }}</strong> coins/min
                </div>
                <div class="badge bg-light text-dark p-3 text-start">
                  Effective call start rule: <strong>max(minimum balance, selected call rate)</strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <form method="post" action="{{ route('admin.settings.calls.update') }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header">
        <h5 class="mb-0">Rates, Balance Rules, and Billing Shares</h5>
      </div>

      <div class="card-body">
        <div class="row g-3">
          @foreach($definitions as $key => $definition)
            <div class="col-md-6">
              <label class="form-label">{{ $definition['label'] }}</label>
              <input
                type="number"
                name="calls[{{ str_replace('calls.', '', $key) }}]"
                class="form-control @error('calls.' . str_replace('calls.', '', $key)) is-invalid @enderror"
                value="{{ old('calls.' . str_replace('calls.', '', $key), $values[$key]) }}"
                min="{{ $definition['min'] ?? 0 }}"
                @if(isset($definition['max'])) max="{{ $definition['max'] }}" @endif
                @if(isset($definition['step'])) step="{{ $definition['step'] }}" @else step="1" @endif
              >
              @error('calls.' . str_replace('calls.', '', $key))
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
              <small class="text-muted">{{ $definition['hint'] }}</small>
            </div>
          @endforeach
        </div>
      </div>

      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">
          Audio/video rates drive the live users page and call creation snapshot. Billing always uses the stored session rate.
        </div>
        <button class="btn btn-primary">
          <i class="ti ti-device-floppy me-1"></i> Save Call Settings
        </button>
      </div>
    </form>
  </div>
@endsection
