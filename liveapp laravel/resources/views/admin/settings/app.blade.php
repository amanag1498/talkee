@extends('layouts.admin-berry')

@section('title', 'App Settings')

@section('content')
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-toggle-left"></i> Global Feature Controls</span>
          <h1 class="admin-page-title">App Settings</h1>
          <p class="admin-page-subtitle">
            Control maintenance mode, force-upgrade signaling, premium theme variants, and Android feature availability from one admin page.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="card border-0 shadow-none bg-transparent">
            <div class="card-body p-0">
              <div class="d-grid gap-2">
                <div class="badge bg-light text-dark p-3 text-start">
                  Public app bootstrap endpoint: <strong><code>/api/app-config</code></strong>
                </div>
                <div class="badge bg-light text-dark p-3 text-start">
                  Maintenance mode bypass: <strong>admin panel, health checks, app settings endpoint</strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <form method="post" action="{{ route('admin.settings.app.update') }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header">
        <h5 class="mb-0">General Controls and Platform Feature Flags</h5>
      </div>

      <div class="card-body">
        <div class="row g-4">
          @foreach($groups as $groupKey => $groupLabel)
            <div class="col-12 {{ in_array($groupKey, ['general', 'host_goals']) ? '' : 'col-xl-4' }}">
              <div class="border rounded-3 p-3 h-100">
                <h6 class="mb-3">{{ $groupLabel }}</h6>
                <div class="d-grid gap-3">
                  @foreach($definitions as $key => $definition)
                    @continue(($definition['group'] ?? 'general') !== $groupKey)
                    @php($field = str_replace('app_features.', '', $key))
                    @php($fieldName = str_replace('.', '][', $field))
                    @php($inputName = "app_features[{$fieldName}]")
                    <div class="d-flex align-items-start justify-content-between gap-3 rounded-3 border p-3">
                      <div>
                        <div class="fw-semibold">{{ $definition['label'] }}</div>
                        @if(!empty($definition['hint']))
                          <small class="text-muted">{{ $definition['hint'] }}</small>
                        @endif
                      </div>
                      @if(($definition['type'] ?? 'boolean') === 'string' && !empty($definition['options']))
                        <div class="flex-shrink-0" style="min-width: 200px;">
                          <select
                            class="form-select form-select-sm @error($key) is-invalid @enderror"
                            name="{{ $inputName }}"
                          >
                            @foreach(($definition['options'] ?? []) as $option)
                              <option
                                value="{{ $option }}"
                                @selected(old($key, $values[$key] ?? $definition['default'] ?? null) === $option)
                              >
                                {{ ucfirst($option) }}
                              </option>
                            @endforeach
                          </select>
                        </div>
                      @elseif(($definition['type'] ?? 'boolean') === 'csv_integer_list' || ($definition['type'] ?? 'boolean') === 'string')
                        <div class="flex-shrink-0" style="min-width: 240px;">
                          <input
                            type="text"
                            class="form-control form-control-sm @error($key) is-invalid @enderror"
                            name="{{ $inputName }}"
                            value="{{ old($key, $values[$key] ?? $definition['default'] ?? '') }}"
                            @if(($definition['type'] ?? 'boolean') === 'csv_integer_list') inputmode="numeric" @endif
                          >
                        </div>
                      @else
                        <div class="form-check form-switch m-0">
                          <input type="hidden" name="{{ $inputName }}" value="0">
                          <input
                            class="form-check-input @error($key) is-invalid @enderror"
                            type="checkbox"
                            role="switch"
                            name="{{ $inputName }}"
                            value="1"
                            @checked(old($key, $values[$key] ?? false))
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
          These toggles are stored in <code>app_settings</code> and loaded into config at boot. The maintenance toggle is enforced server-side.
        </div>
        <button class="btn btn-primary">
          <i class="ti ti-device-floppy me-1"></i> Save App Settings
        </button>
      </div>
    </form>
  </div>
@endsection
