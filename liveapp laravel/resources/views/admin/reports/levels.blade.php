@extends('layouts.admin-berry')
@section('title', 'User Levels')

@section('content')
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
          <h4 class="mb-1">User Levels</h4>
          <div class="text-muted">Top spenders, level distribution, and recent level-ups.</div>
        </div>
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
          <div>
            <label class="form-label">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Name or email">
          </div>
          <div>
            <label class="form-label">Level</label>
            <select name="level_id" class="form-select">
              <option value="">Any</option>
              @foreach($levels as $level)
                <option value="{{ $level->id }}" @selected((int) request('level_id') === $level->id)>L{{ $level->level }} · {{ $level->title }}</option>
              @endforeach
            </select>
          </div>
          <button class="btn btn-primary">Apply</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Level Configuration</h6></div>
      <div class="card-body">
        <div class="list-group list-group-flush">
          @foreach($levels as $level)
            <div class="list-group-item px-0">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="fw-semibold">Level {{ $level->level }} · {{ $level->title }}</div>
                  <small class="text-muted">Min spend: {{ number_format($level->min_spend_coins) }} coins</small>
                </div>
                <span class="badge" style="background: {{ $level->badge_color ?: '#6c757d' }}">{{ $distribution[$level->id] ?? 0 }} users</span>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Top Spenders</h6></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
          <tr>
            <th>User</th>
            <th>Level</th>
            <th>Lifetime Spend</th>
            <th></th>
          </tr>
          </thead>
          <tbody>
          @forelse($topSpenders as $user)
            <tr>
              <td>
                <div class="fw-semibold">{{ $user->name }}</div>
                <small class="text-muted">{{ $user->email }}</small>
              </td>
              <td>
                @if($user->level)
                  <span class="badge" style="background: {{ $user->level->badge_color ?: '#6c757d' }}">L{{ $user->level->level }} · {{ $user->level->title }}</span>
                @else
                  <span class="text-muted">Unassigned</span>
                @endif
              </td>
              <td class="fw-semibold">{{ number_format($user->lifetime_spend_coins) }}</td>
              <td class="text-end">
                <a href="{{ route('admin.wallets.show', $user) }}" class="btn btn-sm btn-primary">Open Wallet</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No users found.</td></tr>
          @endforelse
          </tbody>
        </table>
        {{ $topSpenders->links() }}
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header"><h6 class="mb-0">Recent Level-Up History</h6></div>
      <div class="card-body table-responsive">
        <table class="table align-middle">
          <thead class="table-light">
          <tr>
            <th>User</th>
            <th>From</th>
            <th>To</th>
            <th>Lifetime Spend</th>
            <th>Triggered By</th>
            <th>When</th>
          </tr>
          </thead>
          <tbody>
          @forelse($history as $row)
            <tr>
              <td>{{ $row->user?->name ?? 'User #'.$row->user_id }}</td>
              <td>{{ $row->oldLevel?->title ? 'L'.$row->oldLevel->level.' · '.$row->oldLevel->title : '—' }}</td>
              <td>{{ 'L'.$row->newLevel->level.' · '.$row->newLevel->title }}</td>
              <td>{{ number_format($row->lifetime_spend_coins) }}</td>
              <td>{{ $row->triggered_by_transaction_id ? 'Transaction #'.$row->triggered_by_transaction_id : 'Recalculate' }}</td>
              <td>{{ $row->created_at?->format('d M Y, H:i') }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No level history yet.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection
