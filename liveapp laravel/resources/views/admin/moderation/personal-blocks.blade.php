@extends('layouts.admin-berry')
@section('title', 'Personal Blocks')

@section('content')
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card border-0 bg-primary text-white">
      <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div>
          <div class="small text-uppercase opacity-75 mb-2">Personal safety · Separate from host moderation</div>
          <h3 class="text-white mb-2">Personal User Blocks</h3>
          <p class="mb-0 opacity-75">Review user-to-user restrictions covering profiles, rooms, follows, calls, gifts, and PK interactions. Admin removals are audited.</p>
        </div>
        <div class="align-self-lg-center">
          <a href="{{ route('admin.moderation.blocked-users') }}" class="btn btn-light">View Host Blocks</a>
        </div>
      </div>
    </div>
  </div>
  @foreach([
    ['Active Blocks', $summary['total'], 'Current relationships'],
    ['Blocking Users', $summary['blockers'], 'Unique blockers'],
    ['Blocked Users', $summary['blocked_users'], 'Unique blocked users'],
    ['Blocked Hosts', $summary['host_targets'], 'Blocks targeting hosts'],
  ] as [$label, $value, $meta])
    <div class="col-xl-3 col-md-6">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">{{ $label }}</div>
        <div class="h3 mb-1">{{ number_format($value) }}</div>
        <div class="small text-muted">{{ $meta }}</div>
      </div></div>
    </div>
  @endforeach
</div>

<div class="card mb-4">
  <div class="card-header"><h5 class="mb-0">Search and filters</h5></div>
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-lg-4"><label class="form-label">User</label><input name="q" value="{{ request('q') }}" class="form-control" placeholder="ID, name, or email"></div>
      <div class="col-lg-2"><label class="form-label">Blocker ID</label><input type="number" min="1" name="blocker_user_id" value="{{ request('blocker_user_id') }}" class="form-control"></div>
      <div class="col-lg-2"><label class="form-label">Blocked ID</label><input type="number" min="1" name="blocked_user_id" value="{{ request('blocked_user_id') }}" class="form-control"></div>
      <div class="col-lg-2"><label class="form-label">From</label><input type="date" name="from" value="{{ request('from') }}" class="form-control"></div>
      <div class="col-lg-2"><label class="form-label">To</label><input type="date" name="to" value="{{ request('to') }}" class="form-control"></div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Apply filters</button><a href="{{ route('admin.moderation.personal-blocks') }}" class="btn btn-light border">Reset</a></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">Block relationships</h5><span class="badge bg-light text-dark">{{ number_format($rows->total()) }} results</span></div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light"><tr><th>Blocking user</th><th>Blocked user</th><th>Effect</th><th>Created</th><th class="text-end">Admin override</th></tr></thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td>
            @if($row->blocker)<a class="fw-semibold" href="{{ route('admin.users.show', $row->blocker) }}">{{ $row->blocker->name }}</a>@else Deleted user @endif
            <div class="small text-muted">#{{ $row->blocker_user_id }} · {{ $row->blocker?->email ?? 'No email' }}</div>
            @if($row->blocker?->host)<span class="badge bg-primary mt-1">Host</span>@endif
          </td>
          <td>
            @if($row->blockedUser)<a class="fw-semibold" href="{{ route('admin.users.show', $row->blockedUser) }}">{{ $row->blockedUser->name }}</a>@else Deleted user @endif
            <div class="small text-muted">#{{ $row->blocked_user_id }} · {{ $row->blockedUser?->email ?? 'No email' }}</div>
            @if($row->blockedUser?->host)<span class="badge bg-warning text-dark mt-1">Host target</span>@endif
          </td>
          <td class="text-muted">Rooms, profiles, follows, calls, gifts, and PK interactions are restricted.</td>
          <td class="text-nowrap">{{ $row->created_at?->format('d M Y, H:i') ?? '—' }}</td>
          <td>
            <form method="post" action="{{ route('admin.moderation.personal-blocks.destroy', $row) }}" class="d-flex flex-column gap-2 ms-auto" style="max-width: 260px" onsubmit="return confirm('Remove this personal block? This action is audited.')">
              @csrf
              @method('DELETE')
              <input name="reason" class="form-control form-control-sm" maxlength="500" placeholder="Support reason (optional)">
              <button class="btn btn-sm btn-danger"><i class="ti ti-lock-open me-1"></i>Remove block</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-center text-muted py-5">No personal blocks match the current filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $rows->withQueryString()->links() }}</div>
</div>
@endsection
