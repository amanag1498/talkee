<!doctype html>
<html lang="en">
  <head>
    <title>@yield('title','Agency Dashboard') | Berry</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="{{ asset('berry/assets/images/talkieo-logo.png') }}" type="image/png" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" id="main-font-link" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/phosphor/duotone/style.css') }}">
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/tabler-icons.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/feather.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/fontawesome.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/material.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/css/style.css') }}" id="main-style-link" />
    <link rel="stylesheet" href="{{ asset('berry/assets/css/style-preset.css') }}" />
    <style>
      :root {
        --agency-bg: #f3f6fb;
        --agency-surface: rgba(255, 255, 255, 0.88);
        --agency-border: rgba(15, 23, 42, 0.08);
        --agency-text: #0f172a;
        --agency-muted: #64748b;
        --agency-brand: #0f766e;
        --agency-brand-dark: #115e59;
        --agency-brand-soft: rgba(15, 118, 110, 0.12);
        --agency-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        --agency-radius: 20px;
      }
      body {
        background:
          radial-gradient(circle at top left, rgba(15, 118, 110, 0.10), transparent 28%),
          radial-gradient(circle at top right, rgba(245, 158, 11, 0.10), transparent 20%),
          linear-gradient(180deg, #f8fbff 0%, var(--agency-bg) 100%);
        color: var(--agency-text);
        font-family: 'Manrope', sans-serif;
      }
      .pc-sidebar {
        background: linear-gradient(180deg, #0f172a 0%, #111827 48%, #172033 100%);
        box-shadow: 18px 0 40px rgba(15, 23, 42, 0.18);
      }
      .pc-sidebar .pc-caption label,
      .pc-sidebar .pc-caption i,
      .pc-sidebar .pc-link,
      .pc-sidebar .pc-micon i { color: rgba(226, 232, 240, 0.88) !important; }
      .pc-sidebar .pc-link { border-radius: 14px; margin: 0.2rem 0.55rem; transition: 0.18s ease; }
      .pc-sidebar .pc-link.active,
      .pc-sidebar .pc-link:hover {
        background: linear-gradient(90deg, rgba(15, 118, 110, 0.35), rgba(15, 118, 110, 0.12));
        border: 1px solid rgba(45, 212, 191, 0.16);
      }
      .pc-sidebar .pc-navbar-card {
        background: linear-gradient(135deg, var(--agency-brand) 0%, var(--agency-brand-dark) 100%) !important;
        border: 1px solid rgba(255,255,255,0.08);
      }
      .pc-header { backdrop-filter: blur(12px); background: rgba(248, 250, 252, 0.82); border-bottom: 1px solid rgba(148, 163, 184, 0.12); }
      .pc-container { background: transparent; }
      .pc-content { padding-top: 1rem; }
      .admin-page-shell { display: grid; gap: 1rem; }
      .admin-page-hero {
        position: relative;
        overflow: hidden;
        border-radius: 26px;
        background: linear-gradient(135deg, #ffffff 0%, #f6fbff 48%, #eefbf8 100%);
        border: 1px solid var(--agency-border);
        box-shadow: var(--agency-shadow);
        padding: 1.1rem 1.25rem;
      }
      .admin-page-eyebrow {
        display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.38rem 0.8rem;
        border-radius: 999px; background: var(--agency-brand-soft); color: var(--agency-brand-dark);
        font-size: 0.82rem; font-weight: 700;
      }
      .admin-page-title { font-size: clamp(1.35rem, 1.55vw, 1.9rem); font-weight: 800; letter-spacing: -0.03em; margin: 0 0 0.18rem; line-height: 1.15; }
      .admin-page-subtitle { color: var(--agency-muted); margin: 0; max-width: 680px; font-size: 0.92rem; line-height: 1.45; }
      .admin-page-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.7rem; }
      .card { background: var(--agency-surface); backdrop-filter: blur(8px); border: 1px solid var(--agency-border); border-radius: var(--agency-radius); box-shadow: var(--agency-shadow); }
      .card-header { background: transparent; border-bottom: 1px solid rgba(148,163,184,0.14); padding: 1.15rem 1.25rem; }
      .card-body, .card-footer { padding: 1.2rem 1.25rem; }
      .table { --bs-table-bg: transparent; --bs-table-striped-bg: rgba(248,250,252,0.82); }
      .table thead.table-light th, .table thead th { background: #f8fafc !important; color: #475569; font-size: 0.79rem; font-weight: 800; text-transform: uppercase; }
      .table td, .table th { border-color: rgba(148,163,184,0.16); padding-top: 0.9rem; padding-bottom: 0.9rem; }
      .form-control, .form-select { min-height: 46px; border-radius: 14px; border-color: rgba(148,163,184,0.28); background: rgba(255,255,255,0.96); box-shadow: none; }
      .form-control:focus, .form-select:focus { border-color: rgba(15,118,110,0.45); box-shadow: 0 0 0 0.22rem rgba(15,118,110,0.12); }
      .btn { border-radius: 14px; font-weight: 700; letter-spacing: 0.01em; }
      .btn-primary, .bg-primary { background: linear-gradient(135deg, var(--agency-brand) 0%, var(--agency-brand-dark) 100%) !important; border-color: transparent !important; }
      .alert { border: 0; border-radius: 16px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06); }
      .badge { border-radius: 999px; padding: 0.45rem 0.65rem; font-weight: 700; }
      .metric-chip {
        display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.55rem 0.8rem;
        border-radius: 999px; background: rgba(255,255,255,0.14); color: #fff; font-weight: 600;
      }
      .agency-stat-card .stat-value { font-size: 1.9rem; font-weight: 800; line-height: 1; }
      .agency-stat-card .stat-meta { color: #64748b; font-size: 0.84rem; }
      .agency-top-list { display: grid; gap: 0.85rem; }
      .agency-top-item { display: flex; justify-content: space-between; gap: 1rem; padding-bottom: 0.8rem; border-bottom: 1px solid rgba(148,163,184,0.14); }
      .agency-top-item:last-child { border-bottom: 0; padding-bottom: 0; }
      .agency-status-dot { width: 0.7rem; height: 0.7rem; border-radius: 999px; display: inline-block; }
      .agency-status-dot.online { background: #22c55e; }
      .agency-status-dot.offline { background: #cbd5e1; }
    </style>
    @stack('styles')
  </head>
  <body>
    <div class="loader-bg"><div class="loader-track"><div class="loader-fill"></div></div></div>
    <nav class="pc-sidebar">
      @php
        $overviewRoute = $overviewRoute ?? route('agency.dashboard');
        $hostsIndexRoute = $hostsIndexRoute ?? route('agency.hosts.index');
        $callsRoute = $callsRoute ?? route('agency.calls.index');
        $payoutReportsRoute = $payoutReportsRoute ?? route('agency.payout-reports.index');
        $profileRoute = $profileRoute ?? route('agency.profile.show');
        $videoRoomsRoute = $videoRoomsRoute ?? route('agency.video-rooms.index');
        $audioRoomsRoute = $audioRoomsRoute ?? route('agency.audio-rooms.index');
        $pkBattlesRoute = $pkBattlesRoute ?? route('agency.pk-battles.index');
      @endphp
      <div class="navbar-wrapper">
        <div class="m-header">
          <a href="{{ $overviewRoute }}" class="b-brand text-primary">
            <img src="{{ asset('berry/assets/images/talkieo-logo.png') }}" alt="Talkieo" class="logo logo-lg" />
          </a>
        </div>
        <div class="navbar-content">
          <ul class="pc-navbar">
            <li class="pc-item pc-caption"><label>Dashboard</label><i class="ti ti-dashboard"></i></li>
            <li class="pc-item">
              <a href="{{ $overviewRoute }}" class="pc-link {{ request()->routeIs('agency.dashboard') || request()->routeIs('admin.agencies.dashboard') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-dashboard"></i></span><span class="pc-mtext">Overview</span>
              </a>
            </li>
            <li class="pc-item pc-caption"><label>Hosts</label><i class="ti ti-users"></i></li>
            <li class="pc-item">
              <a href="{{ $hostsIndexRoute }}" class="pc-link {{ request()->routeIs('agency.hosts.*') || request()->routeIs('admin.agencies.hosts.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-users-group"></i></span><span class="pc-mtext">Host Roster</span>
              </a>
            </li>
            <li class="pc-item pc-caption"><label>Reports</label><i class="ti ti-building-bank"></i></li>
            <li class="pc-item">
              <a href="{{ $callsRoute }}" class="pc-link {{ request()->routeIs('agency.calls.*') || request()->routeIs('admin.agencies.calls.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-phone"></i></span><span class="pc-mtext">Call Reports</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ $videoRoomsRoute }}" class="pc-link {{ request()->routeIs('agency.video-rooms.*') || request()->routeIs('admin.agencies.video-rooms.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-video"></i></span><span class="pc-mtext">Video Rooms</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ $audioRoomsRoute }}" class="pc-link {{ request()->routeIs('agency.audio-rooms.*') || request()->routeIs('admin.agencies.audio-rooms.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-microphone-2"></i></span><span class="pc-mtext">Audio Rooms</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ $pkBattlesRoute }}" class="pc-link {{ request()->routeIs('agency.pk-battles.*') || request()->routeIs('admin.agencies.pk-battles.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-swords"></i></span><span class="pc-mtext">PK Battles</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ $payoutReportsRoute }}" class="pc-link {{ request()->routeIs('agency.payout-reports.*') || request()->routeIs('admin.agency-payout-reports.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-cash-banknote"></i></span><span class="pc-mtext">Weekly Payouts</span>
              </a>
            </li>
            <li class="pc-item pc-caption"><label>Agency</label><i class="ti ti-building"></i></li>
            <li class="pc-item">
              <a href="{{ $profileRoute }}" class="pc-link {{ request()->routeIs('agency.profile.*') || request()->routeIs('admin.agencies.profile.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-building"></i></span><span class="pc-mtext">Agency Profile</span>
              </a>
            </li>
          </ul>
          <div class="pc-navbar-card rounded mt-3">
            <h6 class="text-white mb-1">Agency Console</h6>
            <p class="text-white opacity-75 mb-2">Hosts, earnings, and payout visibility for your agency.</p>
          </div>
        </div>
      </div>
    </nav>

    <header class="pc-header">
      <div class="header-wrapper">
        <div class="me-auto pc-mob-drp">
          <ul class="list-unstyled">
            <li class="pc-h-item header-mobile-collapse">
              <a href="#" class="pc-head-link head-link-secondary ms-0" id="sidebar-hide"><i class="ti ti-menu-2"></i></a>
            </li>
            <li class="pc-h-item pc-sidebar-popup">
              <a href="#" class="pc-head-link head-link-secondary ms-0" id="mobile-collapse"><i class="ti ti-menu-2"></i></a>
            </li>
          </ul>
        </div>
        <div class="ms-auto">
          <ul class="list-unstyled d-flex align-items-center gap-2">
            <li class="dropdown pc-h-item header-user-profile">
              <a class="pc-head-link head-link-primary dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button">
                <img src="{{ asset('berry/assets/images/user/avatar-2.jpg') }}" alt="user" class="user-avtar" />
                <span><i class="ti ti-settings"></i></span>
              </a>
              <div class="dropdown-menu dropdown-user-profile dropdown-menu-end pc-h-dropdown">
                <div class="dropdown-header">
                  <h5 class="mb-1">{{ auth()->user()->name ?? 'Agency User' }}</h5>
                  <p class="text-muted mb-2">{{ auth()->user()->email ?? '' }}</p>
                  <hr />
                  <form method="post" action="{{ route('logout') }}">@csrf
                    <button class="dropdown-item"><i class="ti ti-logout"></i> Logout</button>
                  </form>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </header>

    <div class="pc-container">
      <div class="pc-content">
        @php
          $pageTitle = trim($__env->yieldContent('title')) ?: 'Agency Dashboard';
          $pageIntro = trim($__env->yieldContent('page_intro')) ?: 'Agency-side visibility into hosts, earnings, and weekly payout readiness.';
        @endphp

        <div class="admin-page-shell">
          <section class="admin-page-hero">
            <div class="row g-3 align-items-center">
              <div class="col-lg-8">
                <span class="admin-page-eyebrow"><i class="ti ti-building-bank"></i>Agency Console</span>
                <h1 class="admin-page-title">{{ $pageTitle }}</h1>
                <p class="admin-page-subtitle">{{ $pageIntro }}</p>
              </div>
              <div class="col-lg-4">
                <div class="admin-page-actions">@yield('page_actions')</div>
              </div>
            </div>
          </section>

          @if(session('ok'))<div class="alert alert-success">{{ session('ok') }}</div>@endif
          @if(session('err'))<div class="alert alert-danger">{{ session('err') }}</div>@endif
          @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
          @if($errors->any())
            <div class="alert alert-danger">
              <ul class="mb-0">
                @foreach($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          <div class="admin-section-stack">
            @yield('content')
          </div>
        </div>
      </div>
    </div>

    <footer class="pc-footer">
      <div class="footer-wrapper container-fluid">
        <div class="row">
          <div class="col-sm-6 my-1"><p class="m-0">© {{ date('Y') }} Talkieo</p></div>
          <div class="col-sm-6 ms-auto my-1">
            <ul class="list-inline footer-link mb-0 justify-content-sm-end d-flex">
              <li class="list-inline-item"><a href="{{ $overviewRoute }}">Agency</a></li>
            </ul>
          </div>
        </div>
      </div>
    </footer>

    <script src="{{ asset('berry/assets/js/plugins/popper.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/simplebar.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/bootstrap.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/fonts/custom-font.js') }}"></script>
    <script src="{{ asset('berry/assets/js/script.js') }}"></script>
    <script src="{{ asset('berry/assets/js/theme.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/feather.min.js') }}"></script>
    <script>
      try {
        layout_change('light');
        font_change('Manrope');
        change_box_container('false');
        layout_caption_change('true');
        layout_rtl_change('false');
        preset_change('preset-1');
        if (window.feather) window.feather.replace();
      } catch (e) {}
    </script>
    <script src="{{ asset('berry/assets/js/plugins/apexcharts.min.js') }}"></script>
    @stack('scripts')
  </body>
</html>
