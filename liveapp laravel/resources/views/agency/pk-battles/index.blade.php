@extends('layouts.agency-berry')
@section('title', 'PK Battles')
@section('page_intro', 'Agency-scoped PK battle history for video rooms involving your hosts.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ $videoRoomsRoute ?? route('agency.video-rooms.index') }}">Video Rooms</a>
  <a class="btn btn-primary" href="{{ $overviewRoute ?? route('agency.dashboard') }}">Dashboard</a>
@endsection

@section('content')
  <section class="row g-3 mb-3">
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Active</small><div class="stat-value mt-1">{{ number_format($summary['active']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Pending</small><div class="stat-value mt-1">{{ number_format($summary['pending']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Completed</small><div class="stat-value mt-1">{{ number_format($summary['completed']) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">PK Coins</small><div class="stat-value mt-1">{{ number_format($summary['total_pk_coins'] ?? 0) }}</div></div></div></div>
  </section>

  <section class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
      <h5 class="mb-0">Battles</h5>
      <form method="get" class="d-flex gap-2 flex-wrap">
        <select name="status" class="form-select">
          <option value="">Any status</option>
          @foreach(['pending','active','completed','cancelled','failed','expired','rejected'] as $status)
            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
          @endforeach
        </select>
        <button class="btn btn-light border">Filter</button>
      </form>
    </div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Battle</th><th>Room A</th><th>Room B</th><th>Status</th><th>Score</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          @forelse($battles as $battle)
            <tr>
              <td>{{ $battle->battle_id }}</td>
              <td>{{ $battle->roomA?->room_id ?? '—' }}<div class="text-muted small">{{ $battle->hostA?->user?->name ?? '—' }}</div></td>
              <td>{{ $battle->roomB?->room_id ?? '—' }}<div class="text-muted small">{{ $battle->hostB?->user?->name ?? '—' }}</div></td>
              <td><span class="badge bg-light text-dark border">{{ ucfirst($battle->status) }}</span></td>
              <td>{{ number_format((int) $battle->score_a) }} - {{ number_format((int) $battle->score_b) }}</td>
              <td class="text-end">
                <a class="btn btn-sm btn-light border" href="{{ request()->routeIs('admin.*') ? route('admin.agencies.pk-battles.show', ['agency' => $agency->id, 'pk_battle' => $battle->id]) : route('agency.pk-battles.show', $battle) }}">View</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No PK battles found.</td></tr>
          @endforelse
        </tbody>
      </table>
      <div class="d-flex justify-content-end">{{ $battles->links() }}</div>
    </div>
  </section>
@endsection
