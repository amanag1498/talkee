@extends('layouts.admin-berry')
@section('title','Edit Live Room')
@section('content')
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="ti ti-edit me-2"></i>Edit Room</h6></div>
      <div class="card-body">
        <form method="post" action="{{ route('admin.live-rooms.update',$live_room) }}" class="vstack gap-3">
          @csrf @method('PUT')
          <div>
            <label class="form-label">Host</label>
            <select name="host_id" class="form-select" required>
              @foreach($hosts as $h)
                <option value="{{ $h->id }}" @selected($h->id==$live_room->host_id)>{{ $h->user?->name }} ({{ $h->stage_name ?? '—' }})</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="form-label">Room ID</label>
            <input name="room_id" class="form-control" required maxlength="100" value="{{ old('room_id',$live_room->room_id) }}">
          </div>
          <div>
            <label class="form-label">Title</label>
            <input name="title" class="form-control" maxlength="150" value="{{ old('title',$live_room->title) }}">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                @foreach(['scheduled','live','ended'] as $st)
                  <option value="{{ $st }}" @selected($st===$live_room->status)>{{ ucfirst($st) }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Scheduled At</label>
              <input type="datetime-local" name="scheduled_at" class="form-control" value="{{ old('scheduled_at', optional($live_room->scheduled_at)->format('Y-m-d\TH:i')) }}">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Started At</label>
              <input type="datetime-local" name="started_at" class="form-control" value="{{ old('started_at', optional($live_room->started_at)->format('Y-m-d\TH:i')) }}">
            </div>
            <div class="col-6">
              <label class="form-label">Ended At</label>
              <input type="datetime-local" name="ended_at" class="form-control" value="{{ old('ended_at', optional($live_room->ended_at)->format('Y-m-d\TH:i')) }}">
            </div>
          </div>
          <div>
            <label class="form-label">End reason</label>
            <input name="end_reason" class="form-control" maxlength="50" value="{{ old('end_reason',$live_room->end_reason) }}">
          </div>
          <div>
            <label class="form-label">Peak viewers</label>
            <input type="number" name="peak_viewers" min="0" class="form-control" value="{{ old('peak_viewers',$live_room->peak_viewers) }}">
          </div>
          <div>
            <label class="form-label">Max speakers</label>
            @php($roomType = $live_room->room_type ?? 'video')
            <input
              type="number"
              name="max_speakers"
              min="1"
              max="{{ data_get($roomSettings, $roomType . '.max_speakers', 4) }}"
              class="form-control"
              value="{{ old('max_speakers',$live_room->max_speakers ?? data_get($roomSettings, $roomType . '.max_speakers', 4)) }}"
            >
            <small class="text-muted">Current room-type cap from Live Room Settings.</small>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="ti ti-check me-1"></i>Update</button>
            <a class="btn btn-light border" href="{{ route('admin.live-rooms.index') }}">Back</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
