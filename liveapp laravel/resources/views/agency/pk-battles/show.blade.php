@extends('layouts.agency-berry')
@section('title', 'PK Battle #' . $pk_battle->id)
@section('page_intro', 'Read-only PK battle detail for agency operations, host matchup, and contribution visibility.')

@section('page_actions')
  <a class="btn btn-light border" href="{{ request()->routeIs('admin.*') ? route('admin.agencies.pk-battles.index', $agency) : route('agency.pk-battles.index') }}">Back to PK Battles</a>
  <a class="btn btn-primary" href="{{ $videoRoomsRoute ?? route('agency.video-rooms.index') }}">Video Rooms</a>
@endsection

@section('content')
  <section class="row g-3 mb-3">
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Status</small><div class="stat-value mt-1">{{ ucfirst($pk_battle->status) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Score A</small><div class="stat-value mt-1">{{ number_format((int) $pk_battle->score_a) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Score B</small><div class="stat-value mt-1">{{ number_format((int) $pk_battle->score_b) }}</div></div></div></div>
    <div class="col-md-3"><div class="card agency-stat-card"><div class="card-body"><small class="text-muted">Duration</small><div class="stat-value mt-1">{{ number_format((int) $pk_battle->duration_seconds) }}s</div></div></div></div>
  </section>

  <section class="row g-3">
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Room A</h5></div>
        <div class="card-body">
          <div class="fw-semibold">{{ $pk_battle->roomA?->room_id ?? '—' }}</div>
          <div class="text-muted small">{{ $pk_battle->hostA?->user?->name ?? '—' }}</div>
        </div>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="card h-100">
        <div class="card-header"><h5 class="mb-0">Room B</h5></div>
        <div class="card-body">
          <div class="fw-semibold">{{ $pk_battle->roomB?->room_id ?? '—' }}</div>
          <div class="text-muted small">{{ $pk_battle->hostB?->user?->name ?? '—' }}</div>
        </div>
      </div>
    </div>
  </section>

  <section class="card mt-3">
    <div class="card-header"><h5 class="mb-0">Event Timeline</h5></div>
    <div class="card-body table-responsive">
      <table class="table align-middle">
        <thead class="table-light"><tr><th>Room</th><th>Type</th><th>Coins</th><th>User</th><th>Created</th></tr></thead>
        <tbody>
          @forelse($pk_battle->events as $event)
            <tr>
              <td>{{ $event->room_id }}</td>
              <td>{{ $event->event_type }}</td>
              <td>{{ number_format((int) $event->coins) }}</td>
              <td>{{ $event->user?->name ?? '—' }}</td>
              <td>{{ optional($event->created_at)->format('d M Y H:i:s') ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No PK events recorded.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
@endsection
