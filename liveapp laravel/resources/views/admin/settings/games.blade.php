@extends('layouts.admin-berry')

@section('title', 'Game Settings')

@section('content')
  @php
    $selectedGame = request('game', 'teen_patti');
    if (!in_array($selectedGame, ['teen_patti', 'greedy'], true)) {
      $selectedGame = 'teen_patti';
    }

    $gameMeta = [
      'teen_patti' => [
        'label' => 'Teen Patti',
        'subtitle' => 'Cards, pot flow, fake bets, payout rule, and room-strip visibility.',
        'dashboard_route' => 'admin.games.teen-patti.dashboard',
        'settings_route' => route('admin.settings.games.edit', ['game' => 'teen_patti']),
        'accent' => 'warning',
      ],
      'greedy' => [
        'label' => 'Greedy',
        'subtitle' => 'Spinner timing, fake bets, weighted pots, multipliers, and sector distribution.',
        'dashboard_route' => 'admin.games.greedy.dashboard',
        'settings_route' => route('admin.settings.games.edit', ['game' => 'greedy']),
        'accent' => 'primary',
      ],
    ];

    $selectedMeta = $gameMeta[$selectedGame];
    $prefix = "games.{$selectedGame}.";

    $filteredDefinitions = collect($definitions)
      ->filter(fn ($definition, $key) => str_starts_with($key, $prefix));

    $groupedDefinitions = [];
    foreach ($groups as $groupKey => $groupLabel) {
      $items = $filteredDefinitions->filter(fn ($definition) => ($definition['group'] ?? 'general') === $groupKey);
      if ($items->isNotEmpty()) {
        $groupedDefinitions[$groupKey] = [
          'label' => $groupLabel,
          'items' => $items,
        ];
      }
    }

    $groupIcons = [
      'availability' => 'ti ti-toggle-left',
      'limits' => 'ti ti-stack-2',
      'timing' => 'ti ti-clock-hour-4',
      'economy' => 'ti ti-coins',
    ];

    $enabledKey = "games.{$selectedGame}.enabled";
    $visibleKey = "games.{$selectedGame}.visible_in_video_room_strip";
    $fakeKey = "games.{$selectedGame}.fake_bets_enabled";
    $minKey = "games.{$selectedGame}.min_bet";
    $maxKey = "games.{$selectedGame}.max_bet";
    $durationKey = "games.{$selectedGame}.round_duration_seconds";
    $lockKey = "games.{$selectedGame}.betting_lock_seconds";
    $displayKey = "games.{$selectedGame}.result_display_seconds";
  @endphp

  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <div class="row g-3 align-items-center">
        <div class="col-lg-8">
          <span class="admin-page-eyebrow"><i class="ti ti-device-gamepad-2"></i> Real-time Game Controls</span>
          <h1 class="admin-page-title">Game Settings</h1>
          <p class="admin-page-subtitle">
            Separate control surfaces for Teen Patti and Greedy. Each tab keeps the round engine, room visibility, fake bets, timing, and payout rules isolated so the admin side stays readable.
          </p>
        </div>
        <div class="col-lg-4">
          <div class="admin-page-actions">
            <a href="{{ route('admin.games.teen-patti.dashboard') }}" class="btn btn-light border">Teen Patti Dashboard</a>
            <a href="{{ route('admin.games.greedy.dashboard') }}" class="btn btn-light border">Greedy Dashboard</a>
          </div>
        </div>
      </div>
    </section>

    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
          @foreach($gameMeta as $gameKey => $meta)
            <a
              href="{{ $meta['settings_route'] }}"
              class="btn {{ $selectedGame === $gameKey ? 'btn-dark' : 'btn-light border' }}"
            >
              {{ $meta['label'] }}
            </a>
          @endforeach
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-lg-3 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-muted small">Current Game</div>
            <div class="fs-4 fw-semibold">{{ $selectedMeta['label'] }}</div>
            <div class="small text-muted mt-1">{{ $selectedMeta['subtitle'] }}</div>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-muted small">Availability</div>
            <div class="fs-4 fw-semibold">{{ !empty($values[$enabledKey]) ? 'Enabled' : 'Disabled' }}</div>
            <div class="small text-muted mt-1">{{ !empty($values[$visibleKey]) ? 'Visible in video room strip' : 'Hidden from room strip' }}</div>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-muted small">Bet Window</div>
            <div class="fs-4 fw-semibold">{{ $values[$durationKey] ?? '—' }}s</div>
            <div class="small text-muted mt-1">Lock {{ $values[$lockKey] ?? '—' }}s before result, display {{ $values[$displayKey] ?? '—' }}s</div>
          </div>
        </div>
      </div>
      <div class="col-lg-3 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-muted small">Bet Range</div>
            <div class="fs-4 fw-semibold">{{ number_format((int) ($values[$minKey] ?? 0)) }} - {{ number_format((int) ($values[$maxKey] ?? 0)) }}</div>
            <div class="small text-muted mt-1">{{ !empty($values[$fakeKey]) ? 'Fake bets enabled' : 'Fake bets disabled' }}</div>
          </div>
        </div>
      </div>
    </div>

    <form method="post" action="{{ route('admin.settings.games.update', ['game' => $selectedGame]) }}" class="card">
      @csrf
      @method('PUT')

      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-1">{{ $selectedMeta['label'] }} Controls</h5>
          <div class="text-muted small">{{ $selectedMeta['subtitle'] }}</div>
        </div>
        <a href="{{ route($selectedMeta['dashboard_route']) }}" class="btn btn-sm btn-light border">Open Dashboard</a>
      </div>

      <div class="card-body">
        <div class="row g-4">
          @foreach($groupedDefinitions as $groupKey => $group)
            <div class="col-12 {{ $groupKey === 'timing' ? 'col-xl-4' : 'col-xl-6' }}">
              <div class="border rounded-3 p-3 h-100">
                <div class="d-flex align-items-center gap-2 mb-3">
                  <i class="{{ $groupIcons[$groupKey] ?? 'ti ti-adjustments' }}"></i>
                  <h6 class="mb-0">{{ $group['label'] }}</h6>
                </div>
                <div class="d-grid gap-3">
                  @foreach($group['items'] as $key => $definition)
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
                        <div class="flex-shrink-0" style="min-width: 220px;">
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
          <i class="ti ti-device-floppy me-1"></i> Save {{ $selectedMeta['label'] }} Settings
        </button>
      </div>
    </form>
  </div>
@endsection
