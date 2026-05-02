@extends('layouts.admin-berry')
@section('title','PK Battles')

@section('content')
<div class="admin-section-stack">
  <div class="row g-3">
    <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Active</div><div class="h3 mb-0">{{ number_format($summary['active']) }}</div></div></div></div>
    <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Pending</div><div class="h3 mb-0">{{ number_format($summary['pending']) }}</div></div></div></div>
    <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Completed</div><div class="h3 mb-0">{{ number_format($summary['completed']) }}</div></div></div></div>
    <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted small">Cancelled/Failed</div><div class="h3 mb-0">{{ number_format($summary['failed']) }}</div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Total PK Coins</div><div class="h3 mb-0">{{ number_format($summary['total_pk_coins']) }}</div></div></div></div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="ti ti-swords me-2"></i>PK Battles</h5>
      <div class="d-flex gap-2">
        <form method="get" class="d-flex gap-2">
          <select name="status" class="form-select">
            <option value="">Any status</option>
            @foreach(['pending','active','completed','cancelled','rejected','expired','failed'] as $status)
              <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
          </select>
          <button class="btn btn-light border">Filter</button>
        </form>
        <a class="btn btn-primary" href="{{ route('admin.pk-battles.export', ['status' => request('status')]) }}">Export CSV</a>
      </div>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Battle</th><th>Room A / Host A</th><th>Room B / Host B</th><th>Status</th><th>Score</th><th>Winner</th><th>Duration</th><th>Started</th><th>Ended</th><th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
        @forelse($battles as $battle)
          <tr>
            <td class="fw-semibold">{{ $battle->battle_id }}</td>
            <td>
              @if($battle->roomA)<a href="{{ route('admin.live-rooms.show', $battle->roomA) }}">{{ $battle->roomA->room_id }}</a>@else—@endif
              <div class="small text-muted">
                @if($battle->hostA?->user)<a href="{{ route('admin.users.show', $battle->hostA->user) }}">{{ $battle->hostA?->stage_name ?: $battle->hostA->user->name }}</a>@else{{ $battle->hostA?->stage_name ?: $battle->hostA?->user?->name }}@endif
              </div>
            </td>
            <td>
              @if($battle->roomB)<a href="{{ route('admin.live-rooms.show', $battle->roomB) }}">{{ $battle->roomB->room_id }}</a>@else—@endif
              <div class="small text-muted">
                @if($battle->hostB?->user)<a href="{{ route('admin.users.show', $battle->hostB->user) }}">{{ $battle->hostB?->stage_name ?: $battle->hostB->user->name }}</a>@else{{ $battle->hostB?->stage_name ?: $battle->hostB?->user?->name }}@endif
              </div>
            </td>
            <td><span class="badge bg-light text-dark">{{ strtoupper($battle->status) }}</span></td>
            <td>{{ number_format($battle->score_a) }} - {{ number_format($battle->score_b) }}</td>
            <td>{{ $battle->winnerRoom?->room_id ?: 'Draw / N/A' }}</td>
            <td>{{ $battle->duration_seconds }}s</td>
            <td>{{ optional($battle->started_at)->format('d M Y H:i') ?: '—' }}</td>
            <td>{{ optional($battle->ended_at)->format('d M Y H:i') ?: '—' }}</td>
            <td class="text-end"><a class="btn btn-sm btn-light border" href="{{ route('admin.pk-battles.show', $battle) }}">View</a></td>
          </tr>
        @empty
          <tr><td colspan="10" class="text-center text-muted py-4">No PK battles found.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <div class="small text-muted">Top PK hosts: {{ $topHosts->isEmpty() ? 'No completed PKs yet' : $topHosts->map(fn($wins,$hostId) => "Host {$hostId}: {$wins}")->implode(' • ') }}</div>
      <div>{{ $battles->withQueryString()->links() }}</div>
    </div>
  </div>
</div>
@endsection
