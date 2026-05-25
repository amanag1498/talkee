@extends('layouts.admin-berry')

@section('title', 'Game Settings')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-device-gamepad-2"></i> Real-time Game Controls</span>
          <h1 class="admin-page-title">Game Settings</h1>
          <p class="admin-page-subtitle">
            Control Teen Patti availability, round timing, bet limits, payout behavior, and whether the game appears in the video room strip.
          </p>
        </div>
      </div>
    </section>

    <form method="post" action="{{ route('admin.settings.games.update') }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header">
        <h5 class="mb-0">Teen Patti Controls</h5>
      </div>

      <div class="card-body">
        <div class="row g-4">
          @foreach($groups as $groupKey => $groupLabel)
            <div class="col-12 {{ $groupKey === 'timing' ? 'col-xl-4' : 'col-xl-6' }}">
              <div class="border rounded-3 p-3 h-100">
                <h6 class="mb-3">{{ $groupLabel }}</h6>
                <div class="d-grid gap-3">
                  @foreach($definitions as $key => $definition)
                    @continue(($definition['group'] ?? 'general') !== $groupKey)
                    @php($field = str_replace('games.', '', $key))
                    @php($fieldName = str_replace('.', '][', $field))
                    @php($inputName = "games[{$fieldName}]")
                    <div class="d-flex align-items-start justify-content-between gap-3 rounded-3 border p-3">
                      <div>
                        <div class="fw-semibold">{{ $definition['label'] }}</div>
                        @if(!empty($definition['hint']))
                          <small class="text-muted">{{ $definition['hint'] }}</small>
                        @endif
                      </div>
                      @if(($definition['type'] ?? 'boolean') === 'string' && !empty($definition['options']))
                        <div class="flex-shrink-0" style="min-width: 200px;">
                          <select class="form-select form-select-sm @error($key) is-invalid @enderror" name="{{ $inputName }}">
                            @foreach(($definition['options'] ?? []) as $option)
                              <option value="{{ $option }}" @selected(old($key, $values[$key] ?? $definition['default'] ?? null) === $option)>{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                            @endforeach
                          </select>
                        </div>
                      @elseif(($definition['type'] ?? 'boolean') === 'boolean')
                        <div class="form-check form-switch m-0">
                          <input type="hidden" name="{{ $inputName }}" value="0">
                          <input class="form-check-input @error($key) is-invalid @enderror" type="checkbox" role="switch" name="{{ $inputName }}" value="1" @checked(old($key, $values[$key] ?? false))>
                        </div>
                      @else
                        <div class="flex-shrink-0" style="min-width: 180px;">
                          <input
                            type="number"
                            step="{{ $definition['step'] ?? 1 }}"
                            min="{{ $definition['min'] ?? 0 }}"
                            max="{{ $definition['max'] ?? '' }}"
                            class="form-control form-control-sm @error($key) is-invalid @enderror"
                            name="{{ $inputName }}"
                            value="{{ old($key, $values[$key] ?? $definition['default'] ?? '') }}"
                          >
                        </div>
                      @endif
                    </div>
                    @error($key)
                      <div class="text-danger small mt-n2">{{ $message }}</div>
                    @enderror
                  @endforeach
                </div>
              </div>
            </div>
          @endforeach
        </div>
      </div>

      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">
          These values are stored in <code>app_settings</code> and used by Laravel, the websocket service, and the Android client.
        </div>
        <button class="btn btn-primary">
          <i class="ti ti-device-floppy me-1"></i> Save Game Settings
        </button>
      </div>
    </form>
  </div>
@endsection
