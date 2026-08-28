<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Talkieo Account Deletion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --page-bg: #090512;
      --panel: rgba(15, 12, 28, 0.88);
      --border: rgba(255,255,255,0.1);
      --text: #f7f3ff;
      --muted: #b6aec9;
      --accent: #9e7bff;
      --accent-2: #ff7ab6;
      --shadow: 0 32px 80px rgba(0,0,0,.42);
    }

    body {
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(158, 123, 255, 0.22), transparent 25%),
        radial-gradient(circle at top right, rgba(255, 122, 182, 0.18), transparent 22%),
        linear-gradient(145deg, #07040f 0%, #110921 50%, #07040f 100%);
      color: var(--text);
    }

    a { color: #d3c2ff; }
    a:hover { color: #fff; }

    .shell {
      background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
      border: 1px solid var(--border);
      border-radius: 30px;
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
      overflow: hidden;
    }

    .topbar {
      padding: 1.5rem 1.5rem 0;
    }

    .brand-chip {
      display: inline-flex;
      align-items: center;
      gap: .55rem;
      padding: .48rem .85rem;
      border-radius: 999px;
      background: rgba(255,255,255,.05);
      border: 1px solid rgba(255,255,255,.09);
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      font-weight: 700;
    }

    .brand-chip::before {
      content: "";
      width: .7rem;
      height: .7rem;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--accent-2), var(--accent));
      box-shadow: 0 0 18px rgba(158,123,255,.7);
    }

    .title {
      font-size: clamp(2rem, 3vw, 3rem);
      font-weight: 800;
      letter-spacing: -.03em;
      margin-bottom: .5rem;
    }

    .gradient-text {
      background: linear-gradient(135deg, #fff 0%, #d9c7ff 40%, #ffb8d8 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      color: transparent;
    }

    .meta,
    .copy,
    .content p,
    .content li {
      color: var(--muted);
      line-height: 1.8;
    }

    .nav-link-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: .85rem 1rem;
      border-radius: 16px;
      text-decoration: none;
      color: var(--text);
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.045);
      font-weight: 600;
    }

    .nav-link-pill:hover {
      color: #fff;
      background: rgba(255,255,255,.08);
    }

    .content {
      padding: 1.5rem;
    }

    .card-surface {
      padding: 1.65rem;
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,.08);
      background: rgba(255,255,255,.035);
    }

    .content h2 {
      margin-top: 2rem;
      margin-bottom: 1rem;
      font-size: 1.2rem;
      font-weight: 750;
      color: #fff;
      letter-spacing: -.02em;
    }

    .content h2:first-child {
      margin-top: 0;
    }

    .content strong {
      color: #f3ebff;
    }

    .content ul {
      padding-left: 1.25rem;
    }

    .content li::marker {
      color: #ccb8ff;
    }

    .action-box {
      border-radius: 22px;
      padding: 1.25rem;
      background: linear-gradient(135deg, rgba(158,123,255,.14), rgba(255,122,182,.12));
      border: 1px solid rgba(255,255,255,.08);
    }

    .mail-link {
      display: inline-flex;
      align-items: center;
      gap: .6rem;
      padding: .9rem 1.1rem;
      border-radius: 16px;
      text-decoration: none;
      color: #fff;
      font-weight: 700;
      background: linear-gradient(135deg, #9e7bff, #ff7ab6);
      box-shadow: 0 20px 36px rgba(158,123,255,.18);
    }
  </style>
</head>
<body>
  <main class="container py-4 py-md-5">
    <div class="shell">
      <div class="topbar">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <div class="brand-chip mb-3">Talkieo support</div>
            <h1 class="title"><span class="gradient-text">Account Deletion</span></h1>
            <p class="meta mb-0">Request deletion of your Talkieo account and associated account data.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('home') }}" class="nav-link-pill">Home</a>
            <a href="{{ route('privacy-policy') }}" class="nav-link-pill">Privacy Policy</a>
            <a href="{{ route('terms-of-service') }}" class="nav-link-pill">Terms of Service</a>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="card-surface">
          <p class="copy">
            You can permanently delete your Talkieo account inside the app from Settings &gt; Delete Account.
            This page remains available as Talkieo's public deletion resource for users who cannot access the app.
          </p>

          <div class="action-box mt-4">
            <p class="mb-2"><strong>Email support</strong></p>
            <p class="mb-3">Send your account deletion request to <a href="mailto:admin@talkee.in">admin@talkee.in</a> with the email address or phone number linked to your Talkieo account.</p>
            <a
              class="mail-link"
              href="mailto:admin@talkee.in?subject=Talkieo%20account%20deletion%20request">
              Request Account Deletion
            </a>
          </div>

          <h2>What to include</h2>
          <ul>
            <li>Your Talkieo account email address or phone number.</li>
            <li>Your Talkieo user ID or profile name, if available.</li>
            <li>A clear statement that you want your Talkieo account deleted.</li>
          </ul>

          <h2>What happens next</h2>
          <p>
            After we receive your request, Talkieo support will review the request and process account deletion in accordance
            with applicable legal, security, fraud prevention, and retention requirements.
          </p>

          <h2>In-app deletion</h2>
          <p>
            The in-app Delete Account action reauthenticates you, revokes active access, removes personal profile data, and anonymizes
            records that must be retained for legitimate financial, fraud-prevention, moderation, payout, legal, or operational reasons.
          </p>

          <h2>Need help instead?</h2>
          <p>
            If you cannot access the in-app action or need support before permanently deleting your account, contact
            <a href="mailto:admin@talkee.in">admin@talkee.in</a>.
          </p>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
