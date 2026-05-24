<nav class="navbar navbar-expand-lg bg-light px-3 mb-3">
  <a class="navbar-brand d-inline-flex align-items-center gap-2" href="{{ route('admin.dashboard') }}">
    <img src="{{ asset('berry/assets/images/talkieo-logo.png') }}" alt="Talkieo" style="width: 32px; height: 32px; object-fit: contain;">
    <span>Talkieo Admin</span>
  </a>
  <ul class="navbar-nav ms-3">
    <li class="nav-item">
      <a class="nav-link" href="{{ route('admin.agency-requests.index') }}">Agency Requests</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="{{ route('admin.host-requests.index') }}">Host Requests</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" href="{{ route('admin.enroll-requests.index') }}">Enroll Requests</a>
    </li>
  </ul>
  <div class="ms-auto d-flex align-items-center gap-2">
    <span class="text-muted small me-2">{{ auth()->user()->name ?? '' }}</span>
    <form method="post" action="{{ route('logout') }}">@csrf
      <button class="btn btn-sm btn-outline-danger">Logout</button>
    </form>
  </div>
</nav>
