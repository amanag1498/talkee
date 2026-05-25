<!doctype html>
<html lang="en">
  <head>
    <title>@yield('title','Dashboard') | Talkieo</title>
    <!-- [Meta] -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="Berry Bootstrap 5 Admin" />
    <meta name="keywords" content="Bootstrap admin template, dashboard" />
    <meta name="author" content="codedthemes" />

    <!-- [Favicon] -->
    <link rel="icon" href="{{ asset('berry/assets/images/talkieo-logo.png') }}" type="image/png" />

    <!-- [Google Font] -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" id="main-font-link" />

    <!-- [Icon Fonts] -->
    {{-- If your download doesn't have phosphor, delete this line --}}
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/phosphor/duotone/style.css') }}">
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/tabler-icons.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/feather.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/fontawesome.css') }}" />
    <link rel="stylesheet" href="{{ asset('berry/assets/fonts/material.css') }}" />

    <!-- [Template CSS] -->
    <link rel="stylesheet" href="{{ asset('berry/assets/css/style.css') }}" id="main-style-link" />
    <link rel="stylesheet" href="{{ asset('berry/assets/css/style-preset.css') }}" />
    <style>
      :root {
        --admin-bg: #f3f6fb;
        --admin-surface: rgba(255, 255, 255, 0.88);
        --admin-surface-strong: #ffffff;
        --admin-border: rgba(15, 23, 42, 0.08);
        --admin-text: #0f172a;
        --admin-muted: #64748b;
        --admin-brand: #0f766e;
        --admin-brand-dark: #115e59;
        --admin-brand-soft: rgba(15, 118, 110, 0.12);
        --admin-accent: #f59e0b;
        --admin-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        --admin-radius: 20px;
      }

      body {
        background:
          radial-gradient(circle at top left, rgba(15, 118, 110, 0.10), transparent 28%),
          radial-gradient(circle at top right, rgba(245, 158, 11, 0.10), transparent 20%),
          linear-gradient(180deg, #f8fbff 0%, var(--admin-bg) 100%);
        color: var(--admin-text);
        font-family: 'Manrope', sans-serif;
      }

      .pc-sidebar {
        background: linear-gradient(180deg, #0f172a 0%, #111827 48%, #172033 100%);
        box-shadow: 18px 0 40px rgba(15, 23, 42, 0.18);
      }

      .pc-sidebar .navbar-content {
        scrollbar-width: thin;
      }

      .pc-sidebar .pc-caption label,
      .pc-sidebar .pc-caption i,
      .pc-sidebar .pc-link,
      .pc-sidebar .pc-micon i {
        color: rgba(226, 232, 240, 0.88) !important;
      }

      .pc-sidebar .pc-link {
        border-radius: 14px;
        margin: 0.2rem 0.55rem;
        transition: 0.18s ease;
      }

      .pc-sidebar .pc-link.active,
      .pc-sidebar .pc-link:hover {
        background: linear-gradient(90deg, rgba(15, 118, 110, 0.35), rgba(15, 118, 110, 0.12));
        border: 1px solid rgba(45, 212, 191, 0.16);
      }

      .pc-sidebar .pc-navbar-card {
        background: linear-gradient(135deg, var(--admin-brand) 0%, var(--admin-brand-dark) 100%) !important;
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
      }

      .pc-header {
        backdrop-filter: blur(12px);
        background: rgba(248, 250, 252, 0.82);
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
      }

      .pc-container {
        background: transparent;
      }

      .pc-content {
        padding-top: 1rem;
      }

      .admin-page-shell {
        display: grid;
        gap: 1rem;
      }

      .admin-page-hero {
        position: relative;
        overflow: hidden;
        border-radius: 26px;
        background: linear-gradient(135deg, #ffffff 0%, #f6fbff 48%, #eefbf8 100%);
        border: 1px solid var(--admin-border);
        box-shadow: var(--admin-shadow);
        padding: 1.1rem 1.25rem;
      }

      .admin-page-hero::after {
        content: "";
        position: absolute;
        inset: auto -40px -60px auto;
        width: 220px;
        height: 220px;
        border-radius: 999px;
        background: radial-gradient(circle, rgba(15, 118, 110, 0.14) 0%, rgba(15, 118, 110, 0) 68%);
        pointer-events: none;
      }

      .admin-page-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.38rem 0.8rem;
        border-radius: 999px;
        background: var(--admin-brand-soft);
        color: var(--admin-brand-dark);
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.01em;
      }

      .admin-page-title {
        font-size: clamp(1.35rem, 1.55vw, 1.9rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        margin: 0 0 0.18rem;
        line-height: 1.15;
      }

      .admin-page-subtitle {
        color: var(--admin-muted);
        margin: 0;
        max-width: 680px;
        font-size: 0.92rem;
        line-height: 1.45;
      }

      .admin-page-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.7rem;
      }

      .admin-page-actions .btn {
        min-width: 138px;
      }

      .alert {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
      }

      .card {
        background: var(--admin-surface);
        backdrop-filter: blur(8px);
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius);
        box-shadow: var(--admin-shadow);
      }

      .card-header {
        background: transparent;
        border-bottom: 1px solid rgba(148, 163, 184, 0.14);
        padding: 1.15rem 1.25rem;
      }

      .card-body,
      .card-footer {
        padding: 1.2rem 1.25rem;
      }

      .card-footer {
        background: transparent;
        border-top: 1px solid rgba(148, 163, 184, 0.14);
      }

      .table {
        --bs-table-bg: transparent;
        --bs-table-striped-bg: rgba(248, 250, 252, 0.82);
      }

      .table thead.table-light th,
      .table thead th {
        background: #f8fafc !important;
        color: #475569;
        font-size: 0.79rem;
        font-weight: 800;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        border-bottom-width: 1px;
      }

      .table td,
      .table th {
        border-color: rgba(148, 163, 184, 0.16);
        padding-top: 0.9rem;
        padding-bottom: 0.9rem;
      }

      .form-control,
      .form-select {
        min-height: 46px;
        border-radius: 14px;
        border-color: rgba(148, 163, 184, 0.28);
        background: rgba(255, 255, 255, 0.96);
        box-shadow: none;
      }

      .form-control:focus,
      .form-select:focus {
        border-color: rgba(15, 118, 110, 0.45);
        box-shadow: 0 0 0 0.22rem rgba(15, 118, 110, 0.12);
      }

      .form-check-input:checked {
        background-color: var(--admin-brand);
        border-color: var(--admin-brand);
      }

      .btn {
        border-radius: 14px;
        font-weight: 700;
        letter-spacing: 0.01em;
      }

      .btn-primary,
      .bg-primary {
        background: linear-gradient(135deg, var(--admin-brand) 0%, var(--admin-brand-dark) 100%) !important;
        border-color: transparent !important;
      }

      .btn-light.border {
        border-color: rgba(148, 163, 184, 0.24) !important;
      }

      .badge {
        border-radius: 999px;
        padding: 0.45rem 0.65rem;
        font-weight: 700;
      }

      .pagination {
        gap: 0.4rem;
      }

      .page-link {
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 12px !important;
        color: #334155;
      }

      .page-item.active .page-link {
        background: linear-gradient(135deg, var(--admin-brand) 0%, var(--admin-brand-dark) 100%);
        border-color: transparent;
      }

      .admin-section-stack {
        display: grid;
        gap: 1rem;
      }

      @media (max-width: 991.98px) {
        .admin-page-actions {
          justify-content: flex-start;
        }

        .admin-page-hero {
          padding: 1rem;
        }
      }
    </style>
  </head>

  <body>
    <!-- [ Pre-loader ] start -->
    <div class="loader-bg">
      <div class="loader-track">
        <div class="loader-fill"></div>
      </div>
    </div>
    <!-- [ Pre-loader ] End -->

    <!-- [ Sidebar Menu ] start -->
    <nav class="pc-sidebar">
      <div class="navbar-wrapper">
        <div class="m-header">
          <a href="{{ route('admin.dashboard') }}" class="b-brand text-primary">
            <img src="{{ asset('berry/assets/images/talkieo-logo.png') }}" alt="Talkieo" class="logo logo-lg" />
          </a>
        </div>

        <div class="navbar-content">
          @php
            // Pending counters for sidebar badges
            $pendingAgency = \App\Models\AgencyRequest::where('status','pending')->count();
            $pendingHost   = \App\Models\HostRequest::where('status','pending')->count();
            $pendingEnroll = \App\Models\HostEnrollRequest::where('status','pending')->count();
          @endphp

          <ul class="pc-navbar">
            <li class="pc-item pc-caption">
              <label>Talkieo</label>
              <i class="ti ti-dashboard"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.dashboard') }}" class="pc-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-dashboard"></i></span>
                <span class="pc-mtext">Overview</span>
              </a>
            </li>
            <li class="pc-item pc-caption">
              <label>Users</label>
              <i class="ti ti-users"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.users.index') }}" class="pc-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-users"></i></span><span class="pc-mtext">Users</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.hosts.index') }}" class="pc-link {{ request()->routeIs('admin.hosts.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-user-star"></i></span><span class="pc-mtext">Hosts</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.agencies.index') }}" class="pc-link {{ request()->routeIs('admin.agencies.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-building"></i></span><span class="pc-mtext">Agencies</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.levels.index') }}" class="pc-link {{ request()->routeIs('admin.levels.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-layers-linked"></i></span><span class="pc-mtext">Levels</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.user-subscriptions.index') }}" class="pc-link {{ request()->routeIs('admin.user-subscriptions.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-users"></i></span><span class="pc-mtext">User Subscriptions</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.entry-packs.reports') }}" class="pc-link {{ request()->routeIs('admin.entry-packs.reports', 'admin.entry-packs.purchases.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-sparkles"></i></span><span class="pc-mtext">Entry Ownership</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.themes.index') }}" class="pc-link {{ request()->routeIs('admin.themes.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-palette"></i></span><span class="pc-mtext">Themes</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.profile-frames.index') }}" class="pc-link {{ request()->routeIs('admin.profile-frames.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-photo-star"></i></span><span class="pc-mtext">Profile Frames</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Live Ops</label>
              <i class="ti ti-video"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.live-rooms.index') }}" class="pc-link {{ request()->routeIs('admin.live-rooms.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-video"></i></span>
                <span class="pc-mtext">Live Rooms</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.presence.index') }}" class="pc-link {{ request()->routeIs('admin.presence.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-activity"></i></span>
                <span class="pc-mtext">Presence</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.pk-battles.index') }}" class="pc-link {{ request()->routeIs('admin.pk-battles.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-swords"></i></span>
                <span class="pc-mtext">PK Battles</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.calls.index') }}" class="pc-link {{ request()->routeIs('admin.calls.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-phone"></i></span>
                <span class="pc-mtext">Calls</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Games</label>
              <i class="ti ti-device-gamepad-2"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.games.teen-patti.dashboard') }}" class="pc-link {{ request()->routeIs('admin.games.teen-patti.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-cards"></i></span>
                <span class="pc-mtext">Teen Patti</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.games.greedy.dashboard') }}" class="pc-link {{ request()->routeIs('admin.games.greedy.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-casino"></i></span>
                <span class="pc-mtext">Greedy</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.settings.games.edit') }}" class="pc-link {{ request()->routeIs('admin.settings.games.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-adjustments-horizontal"></i></span>
                <span class="pc-mtext">Game Settings</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Monetization</label>
              <i class="ti ti-coins"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.wallets.index') }}" class="pc-link {{ request()->routeIs('admin.wallets.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-coins"></i></span>
                <span class="pc-mtext">Wallets</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.subscription-plans.index') }}" class="pc-link {{ request()->routeIs('admin.subscription-plans.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-crown"></i></span>
                <span class="pc-mtext">Subscription Plans</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.recharge-plans.index') }}" class="pc-link {{ request()->routeIs('admin.recharge-plans.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-wallet"></i></span>
                <span class="pc-mtext">Recharge Plans</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.recharge-audit.index') }}" class="pc-link {{ request()->routeIs('admin.recharge-audit.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-file-invoice"></i></span>
                <span class="pc-mtext">Recharge Audit</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.entry-packs.index') }}" class="pc-link {{ request()->routeIs('admin.entry-packs.index', 'admin.entry-packs.create', 'admin.entry-packs.edit', 'admin.entry-packs.store', 'admin.entry-packs.update', 'admin.entry-packs.destroy') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-sparkles"></i></span>
                <span class="pc-mtext">Entry Packs</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.gifts.index') }}" class="pc-link {{ request()->routeIs('admin.gifts.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-gift"></i></span>
                <span class="pc-mtext">Gifts</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.banners.index') }}" class="pc-link {{ request()->routeIs('admin.banners.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-photo"></i></span>
                <span class="pc-mtext">Banners</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Moderation</label>
              <i class="ti ti-shield-lock"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.moderation.analytics') }}" class="pc-link {{ request()->routeIs('admin.moderation.analytics') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-chart-bar"></i></span>
                <span class="pc-mtext">Analytics</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.moderation.reports') }}" class="pc-link {{ request()->routeIs('admin.moderation.reports') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-flag"></i></span>
                <span class="pc-mtext">Review Queue</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.moderation.blocked-users') }}" class="pc-link {{ request()->routeIs('admin.moderation.blocked-users') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-user-off"></i></span>
                <span class="pc-mtext">Blocked Users</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.moderation.history') }}" class="pc-link {{ request()->routeIs('admin.moderation.history') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-history"></i></span>
                <span class="pc-mtext">History</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.moderation.rules') }}" class="pc-link {{ request()->routeIs('admin.moderation.rules') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-filter-check"></i></span>
                <span class="pc-mtext">Auto Rules</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Reports</label>
              <i class="ti ti-report-analytics"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.reports.hosts') }}" class="pc-link {{ request()->routeIs('admin.reports.hosts*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-report-analytics"></i></span>
                <span class="pc-mtext">Host Reports</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.reports.leaderboards') }}" class="pc-link {{ request()->routeIs('admin.reports.leaderboards*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-trophy"></i></span>
                <span class="pc-mtext">Leaderboards</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.reports.agencies') }}" class="pc-link {{ request()->routeIs('admin.reports.agencies*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-building-bank"></i></span>
                <span class="pc-mtext">Agency Reports</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.agency-payout-reports.index') }}" class="pc-link {{ request()->routeIs('admin.agency-payout-reports*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-cash-banknote"></i></span>
                <span class="pc-mtext">Agency Payouts</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.reports.host-followers') }}" class="pc-link {{ request()->routeIs('admin.reports.host-followers*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-users-group"></i></span>
                <span class="pc-mtext">Followers</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.reports.follow-notifications') }}" class="pc-link {{ request()->routeIs('admin.reports.follow-notifications*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-bell-ringing"></i></span>
                <span class="pc-mtext">Follow Alerts</span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Notifications</label>
              <i class="ti ti-bell"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.notifications.compose') }}"
                 class="pc-link {{ request()->routeIs('admin.notifications.compose') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-send"></i></span>
                <span class="pc-mtext">Compose</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.notifications.index') }}"
                 class="pc-link {{ request()->routeIs('admin.notifications.index') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-bell"></i></span>
                <span class="pc-mtext">Recent</span>
              </a>
            </li>
            <li class="pc-item pc-caption">
              <label>Requests</label>
              <i class="ti ti-news"></i>
            </li>

            <li class="pc-item">
              <a href="{{ route('admin.agency-requests.index') }}" class="pc-link {{ request()->routeIs('admin.agency-requests.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-building"></i></span>
                <span class="pc-mtext d-flex align-items-center justify-content-between w-100">
                  <span>Agency Requests</span>
                  @if($pendingAgency>0)<span class="badge bg-danger">{{ $pendingAgency }}</span>@endif
                </span>
              </a>
            </li>

            <li class="pc-item">
              <a href="{{ route('admin.host-requests.index') }}" class="pc-link {{ request()->routeIs('admin.host-requests.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-user"></i></span>
                <span class="pc-mtext d-flex align-items-center justify-content-between w-100">
                  <span>Host Requests</span>
                  @if($pendingHost>0)<span class="badge bg-danger">{{ $pendingHost }}</span>@endif
                </span>
              </a>
            </li>

            <li class="pc-item">
              <a href="{{ route('admin.enroll-requests.index') }}" class="pc-link {{ request()->routeIs('admin.enroll-requests.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-user-plus"></i></span>
                <span class="pc-mtext d-flex align-items-center justify-content-between w-100">
                  <span>Enroll Requests</span>
                  @if($pendingEnroll>0)<span class="badge bg-danger">{{ $pendingEnroll }}</span>@endif
                </span>
              </a>
            </li>

            <li class="pc-item pc-caption">
              <label>Settings</label>
              <i class="ti ti-adjustments-horizontal"></i>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.settings.app.edit') }}" class="pc-link {{ request()->routeIs('admin.settings.app.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-toggle-left"></i></span>
                <span class="pc-mtext">App Settings</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.settings.calls.edit') }}" class="pc-link {{ request()->routeIs('admin.settings.calls.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-adjustments-horizontal"></i></span>
                <span class="pc-mtext">Call Settings</span>
              </a>
            </li>
            <li class="pc-item">
              <a href="{{ route('admin.settings.live-rooms.edit') }}" class="pc-link {{ request()->routeIs('admin.settings.live-rooms.*') ? 'active' : '' }}">
                <span class="pc-micon"><i class="ti ti-users-plus"></i></span>
                <span class="pc-mtext">Live Room Settings</span>
              </a>
            </li>
          </ul>

          <div class="pc-navbar-card bg-primary rounded mt-3">
            <h6 class="text-white mb-1">Talkieo Console</h6>
            <p class="text-white opacity-75 mb-2">Platform operations and approvals</p>
          </div>

          <div class="w-100 text-center mt-3">
            <div class="badge theme-version badge rounded-pill bg-light text-dark f-12"></div>
          </div>
        </div>
      </div>
    </nav>
    <!-- [ Sidebar Menu ] end -->

    <!-- [ Header Topbar ] start -->
    <header class="pc-header">
      <div class="header-wrapper">
        <div class="me-auto pc-mob-drp">
          <ul class="list-unstyled">
            <li class="pc-h-item header-mobile-collapse">
              <a href="#" class="pc-head-link head-link-secondary ms-0" id="sidebar-hide">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
            <li class="pc-h-item pc-sidebar-popup">
              <a href="#" class="pc-head-link head-link-secondary ms-0" id="mobile-collapse">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
          </ul>
        </div>

        <div class="ms-auto">
          <ul class="list-unstyled d-flex align-items-center gap-2">

            {{-- 🔔 Notifications bell --}}
            @php
              $me = auth()->user();
              $unreadCount = $me?->unreadNotifications()->count() ?? 0;
              $latest = $me?->notifications()->latest()->limit(10)->get() ?? collect();
            @endphp
            <li class="dropdown pc-h-item">
              <a
                class="pc-head-link head-link-secondary dropdown-toggle arrow-none me-0 position-relative"
                data-bs-toggle="dropdown"
                href="#"
                role="button"
                aria-haspopup="false"
                aria-expanded="false"
              >
                <i class="ti ti-bell"></i>
                @if($unreadCount > 0)
                  <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    {{ $unreadCount }}
                  </span>
                @endif
              </a>
              <div class="dropdown-menu dropdown-notification dropdown-menu-end pc-h-dropdown" style="min-width: 360px;">
                <div class="dropdown-header d-flex align-items-center justify-content-between">
                  <h5 class="mb-0">Notifications</h5>
                  <form method="post" action="{{ route('admin.notifications.read-all') }}">@csrf
                    <button class="btn btn-link p-0 text-decoration-underline">Mark all read</button>
                  </form>
                </div>

                <div class="dropdown-header px-0 text-wrap header-notification-scroll position-relative"
                     style="max-height: calc(100vh - 215px)">
                  <div class="list-group list-group-flush w-100">
                    @forelse($latest as $n)
                      @php
                        $d = $n->data;
                        $isUnread = is_null($n->read_at);
                        $type = strtoupper($d['type'] ?? 'APP');
                      @endphp
                      <div class="list-group-item list-group-item-action {{ $isUnread ? '' : 'opacity-75' }}">
                        <div class="d-flex">
                          <div class="flex-shrink-0">
                            <div class="user-avtar bg-light-primary"><i class="ti ti-news"></i></div>
                          </div>
                          <div class="flex-grow-1 ms-2">
                            <div class="d-flex justify-content-between">
                              <strong class="me-2">{{ $type }}</strong>
                              <small class="text-muted">{{ $n->created_at->diffForHumans() }}</small>
                            </div>
                            <div class="text-body fs-6">{{ $d['message'] ?? 'New item' }}</div>
                            <div class="text-muted small">{{ $d['from_name'] ?? '' }} — {{ $d['from_email'] ?? '' }}</div>

                            <div class="mt-2 d-flex gap-2">
                              <form method="post" action="{{ route('admin.notifications.read-one', $n->id) }}">
                                @csrf
                                <button class="btn btn-sm btn-primary">Open</button>
                              </form>
                              @if($isUnread)
                                <form method="post" action="{{ route('admin.notifications.read-one', $n->id) }}">
                                  @csrf
                                  <button class="btn btn-sm btn-outline-secondary">Mark read</button>
                                </form>
                              @endif
                            </div>
                          </div>
                        </div>
                      </div>
                    @empty
                      <div class="p-3 text-center text-muted">No notifications yet.</div>
                    @endforelse
                  </div>
                </div>
              </div>
            </li>
            {{-- /bell --}}

            {{-- User profile --}}
            <li class="dropdown pc-h-item header-user-profile">
              <a class="pc-head-link head-link-primary dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#" role="button">
                <img src="{{ asset('berry/assets/images/user/avatar-2.jpg') }}" alt="user" class="user-avtar" />
                <span><i class="ti ti-settings"></i></span>
              </a>
              <div class="dropdown-menu dropdown-user-profile dropdown-menu-end pc-h-dropdown">
                <div class="dropdown-header">
                  <h5 class="mb-1">{{ auth()->user()->name ?? 'Admin' }}</h5>
                  <p class="text-muted mb-2">{{ auth()->user()->email ?? '' }}</p>
                  <hr />
                  <form method="post" action="{{ route('logout') }}">@csrf
                    <button class="dropdown-item">
                      <i class="ti ti-logout"></i> Logout
                    </button>
                  </form>
                </div>
              </div>
            </li>

          </ul>
        </div>

      </div>
    </header>
    <!-- [ Header ] end -->

    <!-- [ Main Content ] start -->
    <div class="pc-container">
      <div class="pc-content">
        @php
          $pageTitle = trim($__env->yieldContent('title')) ?: 'Overview';
          $pageIntro = trim($__env->yieldContent('page_intro'));
          if ($pageIntro === '') {
            $pageIntro = match (true) {
              request()->routeIs('admin.dashboard') => 'Track approvals, live operations, wallet distribution, and platform health from one control center.',
              request()->routeIs('admin.users.*') => 'Review users, moderation state, device integrity, and role-linked account details.',
              request()->routeIs('admin.hosts.*') => 'Manage host identities, profile quality, agency linkage, and account readiness.',
              request()->routeIs('admin.agencies.*') => 'Oversee agencies, owner assignments, payouts, and operational standing.',
              request()->routeIs('admin.wallets.*') => 'Inspect balances, transaction history, and coin movement across the platform.',
              request()->routeIs('admin.recharge-audit.*') => 'Audit recharge orders month by month, inspect gateway outcomes, and export a printable monthly recharge report.',
              request()->routeIs('admin.live-rooms.*') => 'Audit live room operations, engagement state, and stream-side administration.',
              request()->routeIs('admin.calls.*') => 'Monitor call volume, billing outcomes, earnings distribution, and completion quality.',
              request()->routeIs('admin.presence.*') => 'Watch realtime presence signals and system availability as they move across the network.',
              request()->routeIs('admin.notifications.*') => 'Compose and review outbound notifications with a cleaner operator workflow.',
              request()->routeIs('admin.gifts.*') => 'Manage gifting inventory, pricing, and monetization options for live interactions.',
              request()->routeIs('admin.themes.*') => 'Manage unlock rules, grants, seasonal windows, and the user-facing premium theme catalog.',
              request()->routeIs('admin.entry-packs.*') => 'Control premium room entry visuals, pricing, and usage visibility for live room entrances.',
              request()->routeIs('admin.banners.*') => 'Control promotional creative, targeting, scheduling, and action routing.',
              request()->routeIs('admin.subscription-plans.*', 'admin.user-subscriptions.*') => 'Manage plans, grants, and subscription coverage with better administrative visibility.',
              request()->routeIs('admin.agency-requests.*', 'admin.host-requests.*', 'admin.enroll-requests.*') => 'Process pending approvals with clearer context and faster decision-making.',
              request()->routeIs('admin.reports.hosts*') => 'Compare host performance, engagement volume, and gift activity over time.',
              request()->routeIs('admin.reports.leaderboards*') => 'Review weekly top users, hosts, and agencies from the leaderboard rollup without scanning raw monetization tables.',
              default => 'Operational control, reporting, and moderation for the Talkieo admin panel.',
            };
          }
        @endphp

        <div class="admin-page-shell">
          <section class="admin-page-hero">
            <div class="row g-3 align-items-center">
              <div class="col-lg-8">
                <h1 class="admin-page-title">{{ $pageTitle }}</h1>
                <p class="admin-page-subtitle">{{ $pageIntro }}</p>
              </div>
              <div class="col-lg-4">
                <div class="admin-page-actions">
                  @yield('page_actions')
                </div>
              </div>
            </div>
          </section>

          @if(session('ok'))  <div class="alert alert-success">{{ session('ok') }}</div> @endif
          @if(session('err')) <div class="alert alert-danger">{{ session('err') }}</div> @endif

          <div class="admin-section-stack">
            @yield('content')
          </div>
        </div>
      </div>
    </div>
    <!-- [ Main Content ] end -->

    <footer class="pc-footer">
      <div class="footer-wrapper container-fluid">
        <div class="row">
          <div class="col-sm-6 my-1">
            <p class="m-0">© {{ date('Y') }} Talkieo</p>
          </div>
          <div class="col-sm-6 ms-auto my-1">
            <ul class="list-inline footer-link mb-0 justify-content-sm-end d-flex">
              <li class="list-inline-item"><a href="{{ route('admin.dashboard') }}">Admin</a></li>
            </ul>
          </div>
        </div>
      </div>
    </footer>

    <!-- [Required JS] -->
    <script src="{{ asset('berry/assets/js/plugins/popper.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/simplebar.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/bootstrap.min.js') }}"></script>

    <script src="{{ asset('berry/assets/js/fonts/custom-font.js') }}"></script>

    <script src="{{ asset('berry/assets/js/script.js') }}"></script>
    <script src="{{ asset('berry/assets/js/theme.js') }}"></script>
    <script src="{{ asset('berry/assets/js/plugins/feather.min.js') }}"></script>

    <script>
      // Init helpers (from Berry)
      try {
        layout_change('light');
        font_change('Manrope');
        change_box_container('false');
        layout_caption_change('true');
        layout_rtl_change('false');
        preset_change('preset-1');
        if (window.feather) window.feather.replace();
      } catch(e) { /* no-op */ }
    </script>

    <!-- [Page Specific JS] (optional; only if your page uses charts) -->
    <script src="{{ asset('berry/assets/js/plugins/apexcharts.min.js') }}"></script>
    <script src="{{ asset('berry/assets/js/pages/dashboard-default.js') }}"></script>
  </body>
</html>
