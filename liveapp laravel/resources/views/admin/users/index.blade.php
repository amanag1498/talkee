@extends('layouts.admin-berry')
@section('title','Users')

@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-users me-2"></i>Users</h5>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search user ID, name, or email">
      <button class="btn btn-light border">Search</button>
    </form>
  </div>

  <div class="card-body table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:48px;"></th>
          <th>#</th>
          <th>Name / Email</th>
          <th>Roles</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      @foreach($users as $u)
        @php
          $rowId = 'u_details_'.$u->id;
          $deviceId = $u->device_id;
          $deviceBlocked = $deviceId ? ($blockedDevices[$deviceId] ?? null) : null;
        @endphp
        <tr>
          <td>
            <button class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#{{ $rowId }}" aria-expanded="false" aria-controls="{{ $rowId }}">
              <i class="ti ti-chevron-down"></i>
            </button>
          </td>
          <td>{{ $u->id }}</td>
          <td>
            <div class="fw-semibold"><a href="{{ route('admin.users.show', $u) }}">{{ $u->name }}</a></div>
            <div class="text-muted small">{{ $u->email }}</div>
          </td>
          <td>
            @forelse($u->getRoleNames() as $r)
              <span class="badge bg-secondary me-1">{{ $r }}</span>
            @empty
              <span class="text-muted small">—</span>
            @endforelse
          </td>
          <td>
            @if($u->is_blocked)
              <span class="badge bg-danger">Blocked</span>
            @else
              <span class="badge bg-success">Active</span>
            @endif
          </td>
          <td class="text-end">
            <a href="{{ route('admin.users.show', $u) }}" class="btn btn-sm btn-primary">
              <i class="ti ti-user-circle me-1"></i>Profile
            </a>
            <a href="{{ route('admin.wallets.show', $u) }}" class="btn btn-sm btn-light border">
              <i class="ti ti-wallet me-1"></i>Wallet
            </a>
            @if($u->is_blocked)
              <form method="post" action="{{ route('admin.users.unblock',$u) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-success">
                  <i class="ti ti-lock-open me-1"></i>Unblock
                </button>
              </form>
            @else
            <a href="{{ route('admin.users.notifications', $u) }}" class="btn btn-sm btn-outline-secondary">
              <i class="ti ti-bell"></i> Notifications
            </a>
              <form method="post" action="{{ route('admin.users.block',$u) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-danger">
                  <i class="ti ti-lock me-1"></i>Block
                </button>
              </form>
            @endif
          </td>
        </tr>

        {{-- Collapsible full details --}}
        <tr class="collapse" id="{{ $rowId }}">
          <td colspan="6" class="bg-light">
            <div class="p-3">
              <div class="row g-3">
                {{-- Identity --}}
                <div class="col-md-4">
                  <div class="card h-100">
                    <div class="card-header py-2"><strong>Identity</strong></div>
                    <div class="card-body small">
                      <div class="mb-1"><span class="text-muted">User ID:</span> <code>{{ $u->id }}</code></div>
                      <div class="mb-1"><span class="text-muted">Firebase UID:</span> <code>{{ $u->firebase_uid ?? '—' }}</code></div>
                      <div class="mb-1">
                        <span class="text-muted">Provider:</span>
                        <span class="badge bg-info text-dark">{{ $u->provider ?? '—' }}</span>
                      </div>
                      <div class="mb-1">
                        <span class="text-muted">Email verified:</span>
                        @if($u->email_verified_at)
                          <span class="badge bg-success">{{ $u->email_verified_at->format('Y-m-d H:i') }}</span>
                        @else
                          <span class="badge bg-warning text-dark">No</span>
                        @endif
                      </div>
                      <div class="mb-1"><span class="text-muted">Created:</span> {{ $u->created_at?->format('Y-m-d H:i') ?? '—' }}</div>
                      <div class="mb-1"><span class="text-muted">Updated:</span> {{ $u->updated_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    </div>
                  </div>
                </div>

                {{-- Device --}}
                <div class="col-md-4">
                  <div class="card h-100">
                    <div class="card-header py-2"><strong>Device</strong></div>
                    <div class="card-body small">
                      <div class="mb-2">
                        <span class="text-muted">Device ID:</span>
                        @if($deviceId)
                          <code class="d-block">{{ $deviceId }}</code>
                        @else
                          <span>—</span>
                        @endif
                      </div>
                      <div class="mb-2">
                        <span class="text-muted">Device status:</span>
                        @if($deviceId)
                          @if($deviceBlocked)
                            <span class="badge bg-danger">Blocked</span>
                            @if($deviceBlocked->expires_at)
                              <span class="text-muted">until {{ $deviceBlocked->expires_at->format('Y-m-d H:i') }}</span>
                            @endif
                          @else
                            <span class="badge bg-success">OK</span>
                          @endif
                        @else
                          <span class="text-muted">—</span>
                        @endif
                      </div>

                      @if($deviceId)
                        <div class="d-flex gap-2">
                          @if($deviceBlocked)
                            <form method="post" action="{{ route('admin.users.device.unblock',$u) }}">@csrf
                              <button class="btn btn-sm btn-outline-success">
                                <i class="ti ti-device-mobile-check me-1"></i>Unblock Device
                              </button>
                            </form>
                          @else
                            <form method="post" action="{{ route('admin.users.device.block',$u) }}" onsubmit="return confirm('Block this device_id for all accounts?')">
                              @csrf
                              <button class="btn btn-sm btn-outline-danger">
                                <i class="ti ti-device-mobile-off me-1"></i>Block Device
                              </button>
                            </form>
                          @endif
                        </div>
                      @endif
                    </div>
                  </div>
                </div>

                {{-- Host / Agency --}}
                <div class="col-md-4">
                  <div class="card h-100">
                    <div class="card-header py-2"><strong>Host / Agency</strong></div>
                    <div class="card-body small">
                      @if($u->host)
                        <div class="mb-1"><span class="text-muted">Host ID:</span> <code>{{ $u->host->id }}</code></div>
                        <div class="mb-1"><span class="text-muted">Stage name:</span> {{ $u->host->stage_name ?? '—' }}</div>
                        <div class="mb-1"><span class="text-muted">Contact:</span> {{ $u->host->contact_phone ?? '—' }}</div>
                        <div class="mb-1">
                          <span class="text-muted">Location:</span>
                          {{ trim(($u->host->city ?? '').' '.($u->host->country ?? '')) ?: '—' }}
                        </div>
                        <div class="mb-1">
                          <span class="text-muted">Host status:</span>
                          @if($u->host->is_blocked)
                            <span class="badge bg-danger">Blocked</span>
                          @else
                            <span class="badge bg-success">Active</span>
                          @endif
                        </div>
                        <div class="mb-2">
                          <span class="text-muted d-block">KYC:</span>
                          @if(is_array($u->host->kyc))
                            <pre class="mb-0 bg-light p-2 border rounded" style="max-height:140px; overflow:auto;">{{ json_encode($u->host->kyc, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                          @else
                            <span>—</span>
                          @endif
                        </div>

                        <hr class="my-2">
                        <div class="mb-1"><strong>Agency</strong></div>
                        @if($u->host->agency)
                          <div class="mb-1"><span class="text-muted">Agency ID:</span> <code>{{ $u->host->agency->id }}</code></div>
                          <div class="mb-1"><span class="text-muted">Name:</span> {{ $u->host->agency->name ?? '—' }}</div>
                          <div class="mb-1"><span class="text-muted">Legal:</span> {{ $u->host->agency->legal_name ?? '—' }}</div>
                          <div class="mb-1"><span class="text-muted">Contact:</span> {{ $u->host->agency->contact_email ?? '—' }} / {{ $u->host->agency->contact_phone ?? '—' }}</div>
                          <div class="mb-1">
                            <span class="text-muted">Status:</span>
                            @if($u->host->agency->is_blocked)
                              <span class="badge bg-danger">Blocked</span>
                            @else
                              <span class="badge bg-success">Active</span>
                            @endif
                          </div>
                        @else
                          <div class="text-muted">No agency</div>
                        @endif
                      @else
                        <div class="text-muted">Not a host</div>
                      @endif
                    </div>
                  </div>
                </div>
              </div> <!-- /row -->
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex justify-content-end">
    {{ $users->links() }}
  </div>
</div>
@endsection
