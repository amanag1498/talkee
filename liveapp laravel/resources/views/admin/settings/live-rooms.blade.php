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
            Capacity values define defaults for new audio and video rooms. Each room type has its own speaker-request
            approval policy, applied immediately to new requests.
          </p>
        </div>
      </div>
    </section>

    <form method="post" action="{{ route('admin.settings.live-rooms.update') }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header">
        <h5 class="mb-0">Capacity and Speaker Approval</h5>
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
              <h6 class="mb-2">Speaker Request Approval</h6>
              <p class="text-muted small">
                Configure video viewers and audio listeners independently. Each toggle affects only its matching room type.
              </p>
              @foreach ($definitions as $key => $definition)
                @continue(!str_starts_with($key, 'live_rooms.speaker_requests.'))
                @php($field = str_replace('live_rooms.speaker_requests.', '', $key))
                <input type="hidden" name="live_rooms[speaker_requests][{{ $field }}]" value="0">
                <div class="form-check form-switch">
                  <input
                    type="checkbox"
                    role="switch"
                    name="live_rooms[speaker_requests][{{ $field }}]"
                    id="live-room-speaker-requests-{{ $field }}"
                    class="form-check-input @error("live_rooms.speaker_requests.{$field}") is-invalid @enderror"
                    value="1"
                    @checked((bool) old("live_rooms.speaker_requests.{$field}", $values[$key]))
                  >
                  <label class="form-check-label fw-semibold" for="live-room-speaker-requests-{{ $field }}">
                    {{ $definition['label'] }}
                  </label>
                  @error("live_rooms.speaker_requests.{$field}")
                    <div class="invalid-feedback">{{ $message }}</div>
                  @enderror
                </div>
                <small class="text-muted d-block mt-2">{{ $definition['hint'] }}</small>
                <div class="alert alert-light border mt-3 mb-0 py-2 small">
                  <strong>Off:</strong> the matching room host must approve. <strong>On:</strong> valid requests for that room type are promoted automatically, subject to capacity and PK/lock restrictions.
                </div>
              @endforeach
            </div>
          </div>

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
          Hosts can still choose lower room capacities. Both approval policies are enforced independently by the backend.
        </div>
        <button class="btn btn-primary">
          <i class="ti ti-device-floppy me-1"></i> Save Live Room Settings
        </button>
      </div>
    </form>
  </div>
@endsection
