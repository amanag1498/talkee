<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance</title>
  <style>
    :root {
      color-scheme: dark;
    }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background:
        radial-gradient(circle at top left, rgba(123, 80, 197, 0.35), transparent 35%),
        radial-gradient(circle at bottom right, rgba(62, 35, 116, 0.45), transparent 40%),
        linear-gradient(180deg, #0e0821 0%, #171133 100%);
      color: #fff;
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      padding: 24px;
    }
    .shell {
      width: min(560px, 100%);
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 28px;
      padding: 32px;
      background: rgba(255,255,255,.06);
      backdrop-filter: blur(16px);
      box-shadow: 0 24px 80px rgba(0,0,0,.32);
    }
    .eyebrow {
      display: inline-block;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .16em;
      text-transform: uppercase;
      color: rgba(255,255,255,.70);
      margin-bottom: 12px;
    }
    h1 {
      margin: 0 0 12px;
      font-size: 34px;
      line-height: 1.05;
    }
    p {
      margin: 0;
      color: rgba(255,255,255,.72);
      line-height: 1.6;
    }
  </style>
</head>
<body>
  <main class="shell">
    <span class="eyebrow">Maintenance mode</span>
    <h1>We are temporarily unavailable.</h1>
    <p>The platform is under maintenance. Please try again shortly.</p>
  </main>
</body>
</html>
