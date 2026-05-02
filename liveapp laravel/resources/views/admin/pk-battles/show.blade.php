@extends('layouts.admin-berry')
@section('title','PK Battle Detail')

@section('content')
<div class="admin-section-stack">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h5 class="mb-1">{{ $pk_battle->battle_id }}</h5>
        <div class="text-muted small">Status: {{ strtoupper($pk_battle->status) }} | Winner: {{ $pk_battle->winnerRoom?->room_id ?: 'Draw / N/A' }}</div>
      </div>
      <a class="btn btn-light border" href="{{ route('admin.pk-battles.index') }}">Back</a>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="p-3 border rounded">
            <div class="text-muted small">Room A</div>
            <div class="fw-semibold">@if($pk_battle->roomA)<a href="{{ route('admin.live-rooms.show', $pk_battle->roomA) }}">{{ $pk_battle->roomA->room_id }}</a>@endif</div>
            <div>@if($pk_battle->hostA?->user)<a href="{{ route('admin.users.show', $pk_battle->hostA->user) }}">{{ $pk_battle->hostA?->stage_name ?: $pk_battle->hostA->user->name }}</a>@else{{ $pk_battle->hostA?->stage_name ?: $pk_battle->hostA?->user?->name }}@endif</div>
            <div class="mt-2 h4 mb-0">{{ number_format($pk_battle->score_a) }}</div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-3 border rounded">
            <div class="text-muted small">Room B</div>
            <div class="fw-semibold">@if($pk_battle->roomB)<a href="{{ route('admin.live-rooms.show', $pk_battle->roomB) }}">{{ $pk_battle->roomB->room_id }}</a>@endif</div>
            <div>@if($pk_battle->hostB?->user)<a href="{{ route('admin.users.show', $pk_battle->hostB->user) }}">{{ $pk_battle->hostB?->stage_name ?: $pk_battle->hostB->user->name }}</a>@else{{ $pk_battle->hostB?->stage_name ?: $pk_battle->hostB?->user?->name }}@endif</div>
            <div class="mt-2 h4 mb-0">{{ number_format($pk_battle->score_b) }}</div>
          </div>
        </div>
      </div>

      <div class="row g-3 mt-1">
        <div class="col-md-4"><div class="p-3 border rounded"><div class="text-muted small">Duration</div><div class="fw-semibold">{{ $pk_battle->duration_seconds }}s</div></div></div>
        <div class="col-md-4"><div class="p-3 border rounded"><div class="text-muted small">Started At</div><div class="fw-semibold">{{ optional($pk_battle->started_at)->format('d M Y H:i:s') ?: '—' }}</div></div></div>
        <div class="col-md-4"><div class="p-3 border rounded"><div class="text-muted small">Ended At</div><div class="fw-semibold">{{ optional($pk_battle->ended_at)->format('d M Y H:i:s') ?: '—' }}</div></div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Gift Contributors</h5></div>
    <div class="card-body table-responsive">
      <table class="table">
        <thead class="table-light"><tr><th>User</th><th>Total Coins</th><th>Contributions</th></tr></thead>
        <tbody>
          @forelse($contributors as $row)
            <tr>
              <td>@if($row->user)<a href="{{ route('admin.users.show', $row->user) }}">{{ $row->user->name }}</a>@else{{ 'User #'.$row->user_id }}@endif</td>
              <td>{{ number_format($row->total_coins) }}</td>
              <td>{{ number_format($row->contributions) }}</td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-muted py-4">No contributors yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-0">Event Log</h5></div>
    <div class="card-body table-responsive">
      <table class="table">
        <thead class="table-light"><tr><th>ID</th><th>Type</th><th>Room</th><th>Coins</th><th>Wallet Tx</th><th>Created At</th></tr></thead>
        <tbody>
          @forelse($pk_battle->events as $event)
            <tr>
              <td>{{ $event->id }}</td>
              <td>{{ strtoupper($event->event_type) }}</td>
              <td>@if($event->room)<a href="{{ route('admin.live-rooms.show', $event->room) }}">{{ $event->room->room_id }}</a>@else{{ $event->room_id }}@endif</td>
              <td>{{ number_format($event->coins) }}</td>
              <td>{{ $event->wallet_transaction_id ?: '—' }}</td>
              <td>{{ optional($event->created_at)->format('d M Y H:i:s') }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No PK events logged.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
