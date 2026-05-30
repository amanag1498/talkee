@extends('layouts.admin-berry')

@section('title', 'Live Room Settings')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-settings"></i> Global Live Room Limits</span>
          <h1 class="admin-page-title">Live Room Settings</h1>
          <p class="admin-page-subtitle">
            These values define the default participant and speaker limits used when new audio and video rooms are created.
            Existing rooms keep the limits already stored on their row.
          </p>
        </div>
      </div>
    </section>

    <form method="post" action="{{ route('admin.settings.live-rooms.update') }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header">
        <h5 class="mb-0">Audio and Video Capacity Defaults</h5>
      </div>

      <div class="card-body">
        <div class="row g-4">
          @foreach (['video' => 'Video Rooms', 'audio' => 'Audio Rooms'] as $type => $label)
            <div class="col-lg-6">
              <div class="border rounded-3 p-3 h-100">
                <h6 class="mb-3">{{ $label }}</h6>
                <div class="row g-3">
                  @foreach ($definitions as $key => $definition)
                    @continue(!str_starts_with($key, "live_rooms.{$type}."))
                    @php($field = str_replace("live_rooms.{$type}.", '', $key))
                    <div class="col-12">
                      <label class="form-label">{{ $definition['label'] }}</label>
                      <input
                        type="number"
                        name="live_rooms[{{ $type }}][{{ $field }}]"
                        class="form-control @error("live_rooms.{$type}.{$field}") is-invalid @enderror"
                        value="{{ old("live_rooms.{$type}.{$field}", $values[$key]) }}"
                        min="{{ $definition['min'] ?? 0 }}"
                        @if(isset($definition['max'])) max="{{ $definition['max'] }}" @endif
                        step="1"
                      >
                      @error("live_rooms.{$type}.{$field}")
                        <div class="invalid-feedback">{{ $message }}</div>
                      @enderror
                      <small class="text-muted">{{ $definition['hint'] }}</small>
                    </div>
                  @endforeach
                </div>
              </div>
            </div>
          @endforeach

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 h-100">
              <h6 class="mb-3">PK Battles</h6>
              <div class="row g-3">
                @foreach ($definitions as $key => $definition)
                  @continue(!str_starts_with($key, 'live_rooms.pk.'))
                  @php($field = str_replace('live_rooms.pk.', '', $key))
                  <div class="col-12">
                    <label class="form-label">{{ $definition['label'] }}</label>
                    <input
                      type="number"
                      name="live_rooms[pk][{{ $field }}]"
                      class="form-control @error("live_rooms.pk.{$field}") is-invalid @enderror"
                      value="{{ old("live_rooms.pk.{$field}", $values[$key]) }}"
                      min="{{ $definition['min'] ?? 0 }}"
                      @if(isset($definition['max'])) max="{{ $definition['max'] }}" @endif
                      step="1"
                    >
                    @error("live_rooms.pk.{$field}")
                      <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="text-muted">{{ $definition['hint'] }}</small>
                  </div>
                @endforeach
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">
          Hosts can still choose lower values per room. These settings define the backend defaults and validation ceiling.
        </div>
        <button class="btn btn-primary">
          <i class="ti ti-device-floppy me-1"></i> Save Live Room Settings
        </button>
      </div>
    </form>
  </div>
@endsection
