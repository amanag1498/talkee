<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LiveApp</title>
  @php
    $viteManifestExists = file_exists(public_path('build/manifest.json'));
    $viteHotExists = file_exists(public_path('hot'));
  @endphp
  @if (!app()->runningUnitTests() && ($viteManifestExists || $viteHotExists))
    @vite(['resources/js/app.js'])
  @endif
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
  @auth
    <h5 class="mb-3">Welcome, {{ auth()->user()->name }}</h5>

    {{-- Role-aware action buttons --}}
    <div class="d-flex flex-wrap gap-2 mb-4">
      {{-- Show Apply buttons only if user is NOT already agency or host --}}
      @unless(auth()->user()->hasAnyRole(['agency','host']))
        <a class="btn btn-outline-primary" href="{{ route('agency.apply') }}">Apply Agency</a>
        <a class="btn btn-outline-primary" href="{{ route('host.apply') }}">Apply Host</a>
      @endunless

      {{-- Only hosts should see Enroll --}}
      @hasrole('host')
        <a class="btn btn-outline-primary" href="{{ route('host.enroll.create') }}">Enroll to Agency</a>
      @endhasrole

      {{-- Only admins see Admin --}}
      @hasrole('admin')
        <a class="btn btn-outline-secondary" href="{{ route('admin.dashboard') }}">Admin</a>
      @endhasrole
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
  {{-- existing role-aware buttons... --}}
  <a class="btn btn-outline-secondary" href="{{ route('me.applications') }}">My Applications</a>
</div>

    <form method="post" action="{{ route('logout') }}">@csrf
      <button class="btn btn-danger">Logout</button>
    </form>
  @else
    <h3 class="mb-3">Login</h3>
    <button id="googleLoginBtn" class="btn btn-primary">Continue with Google</button>
  @endauth
</body>
</html>
