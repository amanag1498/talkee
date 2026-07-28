@extends('layouts.admin-berry')
@section('title', 'Meta App Events')
@section('page_intro', 'Verify Meta SDK delivery, privacy consent, registrations, and server-confirmed purchase events without confusing event health with ad attribution.')

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3" role="navigation" aria-label="Meta App Events sections">
  @foreach(['overview' => 'Overview', 'events' => 'Event Audit', 'setup' => 'Setup & Health'] as $tab => $label)
    <a
      href="{{ route('admin.meta-app-events.index', ['tab' => $tab]) }}"
      class="btn {{ $activeTab === $tab ? 'btn-primary' : 'btn-light border' }}"
      aria-current="{{ $activeTab === $tab ? 'page' : 'false' }}"
    >{{ $label }}</a>
  @endforeach
</div>

@if(!$setup['database_ready'])
  <div class="alert alert-danger">
    <strong>Meta event database migration is missing.</strong>
    Run <code>php artisan migrate --force</code> on the production backend.
    App login and verified recharge remain available while auditing is paused.
  </div>
@endif

<div class="alert alert-info">
  <strong>Event audit is not user acquisition attribution.</strong>
  This page confirms that Talkieo recorded an app event. Meta decides whether an
  install came from a Facebook or Instagram ad inside Ads Manager. Per-user
  campaign attribution requires an MMP attribution callback stored in Talkieo.
</div>

@if($activeTab === 'overview')
  <div class="row g-3 mb-3">
    @foreach([
      ['Events', number_format($summary['events']), 'ti ti-activity'],
      ['Registrations', number_format($summary['registrations']), 'ti ti-user-plus'],
      ['Verified Purchases', number_format($summary['purchases']), 'ti ti-shopping-cart-check'],
      ['Purchase Revenue', '₹'.number_format($summary['revenue'], 2), 'ti ti-currency-rupee'],
    ] as [$label, $value, $icon])
      <div class="col-xl-3 col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">{{ $label }}</div>
                <div class="fs-2 fw-bold mt-1">{{ $value }}</div>
              </div>
              <span class="avtar avtar-lg bg-light-primary text-primary">
                <i class="{{ $icon }} fs-3"></i>
              </span>
            </div>
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="row g-3">
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-1">Conversion Funnel</h5>
          <p class="text-muted small mb-0">Server and app events in the selected data set.</p>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush">
            @forelse($eventBreakdown as $row)
              <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <span class="text-capitalize">{{ str_replace('_', ' ', $row->event_name) }}</span>
                <span class="badge bg-light-primary text-primary">{{ number_format($row->event_count) }}</span>
              </div>
            @empty
              <p class="text-center text-muted my-4">No events have been received yet.</p>
            @endforelse
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-1">Platform & Consent</h5>
          <p class="text-muted small mb-0">ATT choices are reported without device identifiers.</p>
        </div>
        <div class="card-body">
          <div class="row g-3">
            @foreach($platformBreakdown as $row)
              <div class="col-sm-6">
                <div class="border rounded p-3 h-100">
                  <div class="text-muted small text-uppercase">{{ $row->platform_name }}</div>
                  <div class="fs-3 fw-bold mt-1">{{ number_format($row->event_count) }}</div>
                </div>
              </div>
            @endforeach
            <div class="col-sm-6">
              <div class="border border-success rounded p-3 h-100">
                <div class="text-success small text-uppercase">Tracking allowed</div>
                <div class="fs-3 fw-bold mt-1">{{ number_format($consent['allowed']) }}</div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="border border-warning rounded p-3 h-100">
                <div class="text-warning small text-uppercase">Tracking declined</div>
                <div class="fs-3 fw-bold mt-1">{{ number_format($consent['declined']) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
@elseif($activeTab === 'events')
  <div class="card">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end mb-4">
        <input type="hidden" name="tab" value="events">
        <div class="col-lg-3 col-md-6">
          <label class="form-label">Event</label>
          <select name="event_name" class="form-select">
            <option value="">All events</option>
            @foreach($eventNames as $eventName)
              <option value="{{ $eventName }}" @selected(request('event_name') === $eventName)>
                {{ ucwords(str_replace('_', ' ', $eventName)) }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label">Platform</label>
          <select name="platform" class="form-select">
            <option value="">All platforms</option>
            <option value="android" @selected(request('platform') === 'android')>Android</option>
            <option value="ios" @selected(request('platform') === 'ios')>iOS</option>
          </select>
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label">From</label>
          <input type="date" name="from" value="{{ request('from') }}" class="form-control">
        </div>
        <div class="col-lg-2 col-md-6">
          <label class="form-label">To</label>
          <input type="date" name="to" value="{{ request('to') }}" class="form-control">
        </div>
        <div class="col-lg-3 d-flex gap-2">
          <button class="btn btn-primary flex-grow-1"><i class="ti ti-search me-1"></i>Apply</button>
          <a href="{{ route('admin.meta-app-events.index', ['tab' => 'events']) }}" class="btn btn-light border">Reset</a>
        </div>
      </form>

      <div class="table-responsive border rounded">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>When</th>
              <th>Event</th>
              <th>User</th>
              <th>Platform</th>
              <th>Consent</th>
              <th class="text-end">Value</th>
              <th>Audit source</th>
            </tr>
          </thead>
          <tbody>
            @forelse($events as $event)
              <tr>
                <td class="text-nowrap">{{ $event->occurred_at?->format('d M Y H:i') }}</td>
                <td class="fw-semibold text-capitalize">{{ str_replace('_', ' ', $event->event_name) }}</td>
                <td>
                  {{ $event->user?->name ?? '—' }}
                  <div class="small text-muted">{{ $event->user?->email ?? '' }}</div>
                </td>
                <td>
                  {{ ucfirst($event->platform ?? 'server') }}
                  <div class="small text-muted">{{ $event->app_version ?? '' }}</div>
                </td>
                <td>
                  {{ $event->advertiser_tracking_enabled === null
                    ? 'Not reported'
                    : ($event->advertiser_tracking_enabled ? 'Allowed' : 'Declined') }}
                </td>
                <td class="text-end text-nowrap">
                  {{ $event->value !== null ? $event->currency.' '.number_format($event->value, 2) : '—' }}
                </td>
                <td>
                  {{ ucfirst($event->source) }}
                  @if($event->paymentOrder)
                    <div class="small text-muted">{{ $event->paymentOrder->order_id }}</div>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center text-muted py-5">No Meta app events found for these filters.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-end mt-3">{{ $events->links() }}</div>
    </div>
  </div>
@else
  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-1">Integration Health</h5>
          <p class="text-muted small mb-0">Secrets are never displayed in the admin panel.</p>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush">
            @foreach([
              ['Database migration', $setup['database_ready'], $setup['database_ready'] ? 'meta_app_events is ready' : 'Run php artisan migrate --force'],
              ['Meta App ID', filled($setup['app_id']), $setup['app_id'] ?: 'Missing META_APP_ID'],
              ['Client Token', $setup['client_token_configured'], $setup['client_token_configured'] ? 'Configured securely' : 'Missing META_CLIENT_TOKEN'],
              ['Ad Account', filled($setup['ad_account_id']), $setup['ad_account_id'] ?: 'Missing META_AD_ACCOUNT_ID'],
              ['Business Portfolio', filled($setup['business_id']), $setup['business_id'] ?: 'Missing META_BUSINESS_ID'],
              ['Event Pipeline', $setup['server_events'] + $setup['app_events'] > 0, number_format($setup['server_events']).' server / '.number_format($setup['app_events']).' app events'],
            ] as [$label, $healthy, $detail])
              <div class="list-group-item px-0 d-flex justify-content-between align-items-center gap-3">
                <div>
                  <div class="fw-semibold">{{ $label }}</div>
                  <div class="small text-muted mt-1">{{ $detail }}</div>
                </div>
                <span class="badge {{ $healthy ? 'bg-light-success text-success' : 'bg-light-warning text-warning' }}">
                  {{ $healthy ? 'Ready' : 'Action needed' }}
                </span>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="mb-1">Release Checklist</h5>
          <p class="text-muted small mb-0">Complete for every production build.</p>
        </div>
        <div class="card-body">
          <ol class="ps-3 mb-4">
            <li class="mb-2">Link the Meta app, ad account, and Business Portfolio.</li>
            <li class="mb-2">Add native App ID and Client Token files on Android and iOS.</li>
            <li class="mb-2">Build normally; Meta app events are enabled by default.</li>
            <li class="mb-2">Run Laravel migrations before releasing the app.</li>
            <li>Verify install, registration, consent, login, and purchase in App Ads Helper.</li>
          </ol>
          <div class="bg-light rounded p-3 small text-muted">
            Last received:
            {{ $setup['last_event_at']
              ? \Carbon\Carbon::parse($setup['last_event_at'])->format('d M Y H:i')
              : 'No events yet' }}
          </div>
        </div>
      </div>
    </div>
  </div>
@endif
@endsection
