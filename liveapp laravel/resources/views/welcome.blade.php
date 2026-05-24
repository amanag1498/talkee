<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Talkieo</title>
  @php
    $viteManifestExists = file_exists(public_path('build/manifest.json'));
    $viteHotExists = file_exists(public_path('hot'));
  @endphp
  @if (!app()->runningUnitTests() && ($viteManifestExists || $viteHotExists))
    @vite(['resources/js/app.js'])
  @endif
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --page-bg: #090512;
      --panel: rgba(15, 12, 28, 0.86);
      --panel-soft: rgba(255, 255, 255, 0.05);
      --border: rgba(255, 255, 255, 0.11);
      --text: #f6f2ff;
      --muted: #b4abc9;
      --accent: #9e7bff;
      --accent-2: #ff7ab6;
      --accent-3: #6be7ff;
      --shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
    }

    body {
      min-height: 100vh;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(158, 123, 255, 0.24), transparent 28%),
        radial-gradient(circle at top right, rgba(255, 122, 182, 0.18), transparent 25%),
        radial-gradient(circle at bottom center, rgba(107, 231, 255, 0.12), transparent 35%),
        linear-gradient(145deg, #07040f 0%, #10091f 48%, #07040f 100%);
    }

    a { color: #cdbaff; }
    a:hover { color: #f6e3ff; }

    .site-shell {
      position: relative;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
      border: 1px solid var(--border);
      border-radius: 32px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
    }

    .site-shell::before {
      content: "";
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 20% 15%, rgba(158, 123, 255, 0.20), transparent 25%),
        radial-gradient(circle at 80% 12%, rgba(255, 122, 182, 0.16), transparent 24%);
      pointer-events: none;
    }

    .brand-kicker {
      display: inline-flex;
      align-items: center;
      gap: .55rem;
      padding: .5rem .9rem;
      border-radius: 999px;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.1);
      color: #efe8ff;
      font-size: .82rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .brand-dot {
      width: .7rem;
      height: .7rem;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent-2), var(--accent));
      box-shadow: 0 0 18px rgba(158, 123, 255, 0.6);
    }

    .hero-title {
      font-size: clamp(2.4rem, 4vw, 4.4rem);
      line-height: 1.02;
      font-weight: 800;
      letter-spacing: -.04em;
    }

    .hero-title .gradient-text,
    .section-title .gradient-text {
      background: linear-gradient(135deg, #fff 0%, #d9c7ff 40%, #ffb8d8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      color: transparent;
    }

    .hero-copy,
    .muted-copy {
      color: var(--muted);
      line-height: 1.7;
      font-size: 1.02rem;
    }

    .pill-link {
      display: inline-flex;
      align-items: center;
      gap: .55rem;
      padding: .8rem 1rem;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.05);
      color: var(--text);
      text-decoration: none;
      font-weight: 600;
    }

    .pill-link:hover {
      background: rgba(255,255,255,.09);
      color: #fff;
    }

    .stat-card,
    .feature-card,
    .action-card {
      position: relative;
      height: 100%;
      padding: 1.35rem;
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,.09);
      background: rgba(255,255,255,.045);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
    }

    .feature-card h2,
    .action-card h2,
    .section-title {
      color: #fff;
      font-weight: 700;
      letter-spacing: -.02em;
    }

    .stat-value {
      font-size: 1.9rem;
      font-weight: 800;
      color: #fff;
    }

    .glow-button {
      border: none;
      border-radius: 18px;
      background: linear-gradient(135deg, var(--accent), var(--accent-2));
      color: #fff;
      font-weight: 700;
      padding: .95rem 1.15rem;
      box-shadow: 0 16px 30px rgba(158, 123, 255, 0.28);
    }

    .glow-button:hover {
      color: #fff;
      filter: brightness(1.04);
    }

    .outline-dark {
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.04);
      color: #fff;
      font-weight: 600;
    }

    .outline-dark:hover {
      background: rgba(255,255,255,.09);
      color: #fff;
    }

    .overview-list {
      margin: 0;
      padding-left: 1.2rem;
      color: var(--muted);
      line-height: 1.8;
    }

    .overview-list li::marker {
      color: #cbb7ff;
    }

    .hero-mesh {
      min-height: 100%;
      padding: 1.5rem;
      border-radius: 28px;
      background:
        linear-gradient(160deg, rgba(158, 123, 255, 0.18), rgba(255,255,255,0.03)),
        rgba(255,255,255,.035);
      border: 1px solid rgba(255,255,255,.08);
    }

    .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: .7rem;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .6rem .85rem;
      border-radius: 999px;
      font-size: .92rem;
      color: #f3ecff;
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.08);
    }

    .hero-badge span {
      display: inline-block;
      width: .45rem;
      height: .45rem;
      border-radius: 50%;
      background: #9e7bff;
      box-shadow: 0 0 14px rgba(158,123,255,.7);
    }

    @media (max-width: 991.98px) {
      .hero-title {
        font-size: 2.5rem;
      }
    }
  </style>
</head>
<body>
  <main class="container py-4 py-md-5">
    <div class="site-shell p-4 p-md-5">
      <div class="row align-items-start g-4 g-lg-5">
        <div class="col-lg-7">
          <div class="brand-kicker mb-4">
            <span class="brand-dot"></span>
            Talkieo
          </div>
          <h1 class="hero-title mb-4">
            <span class="gradient-text">Live rooms, social audio, gifting, and creator subscriptions</span>
          </h1>
          <p class="hero-copy mb-4">
            Talkieo is a premium live entertainment platform where viewers and creators connect through video rooms,
            audio rooms, real-time gifting, subscriptions, wallet-based experiences, and premium entry effects.
          </p>
          <div class="d-flex flex-wrap gap-3 mb-4">
            <a class="pill-link" href="{{ route('privacy-policy') }}">Privacy Policy</a>
            <a class="pill-link" href="{{ route('terms-of-service') }}">Terms of Service</a>
          </div>
          <div class="hero-badges mb-4">
            <div class="hero-badge"><span></span> Google sign-in support</div>
            <div class="hero-badge"><span></span> Live video + audio rooms</div>
            <div class="hero-badge"><span></span> Wallet, gifts, subscriptions</div>
          </div>
          <div class="row g-3">
            <div class="col-sm-4">
              <div class="stat-card">
                <div class="text-uppercase small text-white-50 mb-2">Experience</div>
                <div class="stat-value">Real-time</div>
                <div class="muted-copy mt-2">Creator rooms, social audio, and audience participation.</div>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="stat-card">
                <div class="text-uppercase small text-white-50 mb-2">Monetization</div>
                <div class="stat-value">Coins</div>
                <div class="muted-copy mt-2">Recharge, gifting, subscriptions, and premium cosmetics.</div>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="stat-card">
                <div class="text-uppercase small text-white-50 mb-2">Operations</div>
                <div class="stat-value">Admin</div>
                <div class="muted-copy mt-2">Host, agency, moderation, and reporting workflows.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="hero-mesh">
            <div class="action-card mb-4">
              <h2 class="h4 mb-3">What Talkieo is for</h2>
              <p class="muted-copy mb-0">
                Talkieo is designed for live creator engagement. Users can discover creators, join live rooms,
                send gifts, manage wallet balance, buy subscriptions, and unlock premium in-room experiences.
              </p>
            </div>

            <div class="action-card">
              <h2 class="h5 mb-3">Quick actions</h2>
              @auth
                <p class="muted-copy mb-3">Signed in as <strong class="text-white">{{ auth()->user()->name }}</strong>.</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                  @unless(auth()->user()->hasAnyRole(['agency','host']))
                    <a class="btn outline-dark" href="{{ route('agency.apply') }}">Apply Agency</a>
                    <a class="btn outline-dark" href="{{ route('host.apply') }}">Apply Host</a>
                  @endunless
                  @hasrole('host')
                    <a class="btn outline-dark" href="{{ route('host.enroll.create') }}">Enroll to Agency</a>
                  @endhasrole
                  @hasrole('admin')
                    <a class="btn outline-dark" href="{{ route('admin.dashboard') }}">Admin</a>
                  @endhasrole
                  <a class="btn outline-dark" href="{{ route('me.applications') }}">My Applications</a>
                </div>
                <form method="post" action="{{ route('logout') }}">
                  @csrf
                  <button class="btn glow-button w-100">Logout</button>
                </form>
              @else
                <p class="muted-copy mb-3">
                  Public legal and product information is available on this page. Sign in only if you want to access account features.
                </p>
                <button id="googleLoginBtn" class="btn glow-button w-100 mb-3">Continue with Google</button>
                <div class="small text-white-50">
                  By continuing, you can review our
                  <a href="{{ route('terms-of-service') }}">Terms of Service</a>
                  and
                  <a href="{{ route('privacy-policy') }}">Privacy Policy</a>.
                </div>
              @endauth
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 g-lg-4 mt-2 mt-lg-4">
        <div class="col-md-4">
          <div class="feature-card">
            <h2 class="h5 mb-3">Creator platform</h2>
            <p class="muted-copy mb-0">
              Hosts and creators use Talkieo for scheduled or spontaneous live sessions, audience interaction, and premium engagement.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <h2 class="h5 mb-3">Viewer ecosystem</h2>
            <p class="muted-copy mb-0">
              Viewers can join rooms, recharge wallet balance, send gifts, purchase subscriptions, and unlock premium experiences.
            </p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card">
            <h2 class="h5 mb-3">Public information</h2>
            <p class="muted-copy mb-0">
              The homepage, privacy policy, and terms pages are available publicly without login for review and compliance needs.
            </p>
          </div>
        </div>
      </div>

      <div class="row mt-4">
        <div class="col-12">
          <div class="feature-card">
            <h2 class="section-title h4 mb-3"><span class="gradient-text">Platform overview</span></h2>
            <ul class="overview-list">
              <li>Live video rooms and social audio rooms for creators and audiences.</li>
              <li>Wallet, gifting, subscriptions, and premium cosmetics such as entry effects and profile items.</li>
              <li>Host, agency, and admin workflows for moderation, reporting, and platform operations.</li>
              <li>Google sign-in support for account access and onboarding.</li>
              <li>Public legal information available without requiring login.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
