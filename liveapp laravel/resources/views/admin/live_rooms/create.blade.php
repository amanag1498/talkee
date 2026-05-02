@extends('layouts.admin-berry')
@section('title','New Live Room')
@section('content')
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="ti ti-video-plus me-2"></i>Create Room</h6></div>
      <div class="card-body">
        <form method="post" action="{{ route('admin.live-rooms.store') }}" class="vstack gap-3">
          @csrf
          <div>
            <label class="form-label">Host</label>
            <select name="host_id" class="form-select" required>
              <option value="">Select host</option>
              @foreach($hosts as $h)
                <option value="{{ $h->id }}">{{ $h->user?->name }} ({{ $h->stage_name ?? '—' }})</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="form-label">Room ID (provider)</label>
            <input name="room_id" class="form-control" required maxlength="100" placeholder="unique room id from provider">
          </div>
          <div>
            <label class="form-label">Title</label>
            <input name="title" class="form-control" maxlength="150" placeholder="optional">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="scheduled">Scheduled</option>
                <option value="live">Live</option>
                <option value="ended">Ended</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Scheduled At</label>
              <input type="datetime-local" name="scheduled_at" class="form-control">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Started At</label>
              <input type="datetime-local" name="started_at" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Ended At</label>
              <input type="datetime-local" name="ended_at" class="form-control">
            </div>
          </div>
          <div>
            <label class="form-label">End reason</label>
            <input name="end_reason" class="form-control" maxlength="50" placeholder="optional">
          </div>
          <div>
            <label class="form-label">Peak viewers</label>
            <input type="number" name="peak_viewers" min="0" value="0" class="form-control">
          </div>
          <div>
            <label class="form-label">Max speakers</label>
            <input
              type="number"
              name="max_speakers"
              min="1"
              max="{{ data_get($roomSettings, 'video.max_speakers', 4) }}"
              value="{{ data_get($roomSettings, 'video.max_speakers', 4) }}"
              class="form-control"
            >
            <small class="text-muted">Default video room cap from Live Room Settings.</small>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><i class="ti ti-check me-1"></i>Save</button>
            <a class="btn btn-light border" href="{{ route('admin.live-rooms.index') }}">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
