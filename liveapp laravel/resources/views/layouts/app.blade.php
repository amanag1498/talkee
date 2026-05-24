<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>@yield('title','Talkieo')</title>
  <link rel="icon" href="{{ asset('berry/assets/images/talkieo-logo.png') }}" type="image/png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-light bg-light px-3">
  <a class="navbar-brand d-inline-flex align-items-center gap-2" href="{{ route('home') }}">
    <img src="{{ asset('berry/assets/images/talkieo-logo.png') }}" alt="Talkieo" style="width: 32px; height: 32px; object-fit: contain;">
    <span>Talkieo</span>
  </a>

  @auth
    <div class="d-flex gap-2">
      {{-- Show Apply buttons only if the user is NOT already an agency or host --}}
      @unless(auth()->user()->hasAnyRole(['agency','host']))
        <a class="btn btn-sm btn-outline-primary" href="{{ route('agency.apply') }}">Apply Agency</a>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('host.apply') }}">Apply Host</a>
      @endunless

      {{-- Only hosts see Enroll --}}
      @hasrole('host')
        <a class="btn btn-sm btn-outline-primary" href="{{ route('host.enroll.create') }}">Enroll to Agency</a>
      @endhasrole

      {{-- Only admins see Admin --}}
      @hasrole('admin')
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.dashboard') }}">Admin</a>
      @endhasrole
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('me.applications') }}">My Applications</a>

  @endauth
</nav>

<main class="container py-4">
  @if(session('ok'))  <div class="alert alert-success">{{ session('ok') }}</div> @endif
  @if(session('err')) <div class="alert alert-danger">{{ session('err') }}</div> @endif

  @yield('content')
</main>
</body>
</html>
