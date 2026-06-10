@extends('layouts.admin-berry')
@section('title','Edit Host #'.$host->id)
@section('content')
<form method="post" action="{{ route('admin.hosts.update',$host) }}" enctype="multipart/form-data" class="vstack gap-3">
  @csrf @method('PUT')

  <div class="card">
    <div class="card-header">
      <h6 class="mb-0"><i class="ti ti-user-star me-2"></i>Edit Host</h6>
    </div>
    <div class="card-body row g-3">
      <div class="col-12">
        <div class="alert alert-light border d-flex flex-wrap gap-4 mb-0">
          <div>
            <small class="text-muted d-block">Followers</small>
            <div class="fw-semibold">{{ $host->followers->count() }}</div>
          </div>
          <div>
            <small class="text-muted d-block">Global Audio Rate</small>
            <div class="fw-semibold">{{ config('calls.audio_coin_rate_per_minute') }} coins/min</div>
          </div>
          <div>
            <small class="text-muted d-block">Global Video Rate</small>
            <div class="fw-semibold">{{ config('calls.video_coin_rate_per_minute') }} coins/min</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Stage Name</label>
        <input class="form-control" name="stage_name" value="{{ old('stage_name',$host->stage_name) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Payout %</label>
        <input type="number" step="0.01" min="0" max="100" class="form-control" name="payout_percentage"
               value="{{ old('payout_percentage',$host->payout_percentage) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Weekly Bonus</label>
        <input type="number" min="0" class="form-control" name="weekly_bonus"
               value="{{ old('weekly_bonus',$host->weekly_bonus) }}">
      </div>
      <div class="col-md-4">
        <label class="form-label">Agency Assignment</label>
        <select class="form-select" name="agency_id">
          <option value="">No agency</option>
          @foreach($agencies as $agency)
            <option value="{{ $agency->id }}" @selected((string) old('agency_id', $host->agency_id) === (string) $agency->id)>
              {{ $agency->name }}
            </option>
          @endforeach
        </select>
        <small class="text-muted">Admin can directly detach or reassign this host without an enroll request.</small>
      </div>
      <div class="col-12">
        <div class="border rounded-3 p-3">
          <div class="fw-semibold mb-1">Host Feature Access</div>
          <div class="text-muted small mb-3">
            These switches control what this host is allowed to start or receive. Existing live sessions are not force-ended.
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <div class="form-check form-switch">
                <input type="hidden" name="video_rooms_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="video_rooms_enabled" name="video_rooms_enabled" value="1" @checked(old('video_rooms_enabled', $host->video_rooms_enabled))>
                <label class="form-check-label" for="video_rooms_enabled">Video Rooms Enabled</label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check form-switch">
                <input type="hidden" name="audio_rooms_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="audio_rooms_enabled" name="audio_rooms_enabled" value="1" @checked(old('audio_rooms_enabled', $host->audio_rooms_enabled))>
                <label class="form-check-label" for="audio_rooms_enabled">Audio Rooms Enabled</label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check form-switch">
                <input type="hidden" name="video_calls_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="video_calls_enabled" name="video_calls_enabled" value="1" @checked(old('video_calls_enabled', $host->video_calls_enabled))>
                <label class="form-check-label" for="video_calls_enabled">Video Calls Enabled</label>
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-check form-switch">
                <input type="hidden" name="audio_calls_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="audio_calls_enabled" name="audio_calls_enabled" value="1" @checked(old('audio_calls_enabled', $host->audio_calls_enabled))>
                <label class="form-check-label" for="audio_calls_enabled">Audio Calls Enabled</label>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Audio Call Rate / min</label>
        <input type="number" min="1" class="form-control" name="audio_call_rate_per_minute"
               value="{{ old('audio_call_rate_per_minute', $host->audio_call_rate_per_minute) }}"
               placeholder="{{ config('calls.audio_coin_rate_per_minute') }}">
        <small class="text-muted">Leave blank to use global audio rate: {{ config('calls.audio_coin_rate_per_minute') }} coins/min.</small>
      </div>
      <div class="col-md-4">
        <label class="form-label">Video Call Rate / min</label>
        <input type="number" min="1" class="form-control" name="video_call_rate_per_minute"
               value="{{ old('video_call_rate_per_minute', $host->video_call_rate_per_minute) }}"
               placeholder="{{ config('calls.video_coin_rate_per_minute') }}">
        <small class="text-muted">Leave blank to use global video rate: {{ config('calls.video_coin_rate_per_minute') }} coins/min.</small>
      </div>
      <div class="col-12">
        <div class="border rounded-3 p-3">
          <div class="fw-semibold mb-1">Host Goal Overrides</div>
          <div class="text-muted small mb-3">
            Optional per-host milestones. Leave any field empty to keep using the global app setting for that goal type.
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Follower Goals</label>
              <input class="form-control" name="goal_followers"
                     value="{{ old('goal_followers', $host->goal_followers) }}"
                     placeholder="25,50,100,250">
              <small class="text-muted">Comma-separated follower milestones for this host only.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">Weekly Live Minute Goals</label>
              <input class="form-control" name="goal_weekly_live_minutes"
                     value="{{ old('goal_weekly_live_minutes', $host->goal_weekly_live_minutes) }}"
                     placeholder="60,180,300,600">
              <small class="text-muted">Comma-separated live-minute milestones for this host only.</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">Weekly Gifted Coin Goals</label>
              <input class="form-control" name="goal_weekly_gifted_coins"
                     value="{{ old('goal_weekly_gifted_coins', $host->goal_weekly_gifted_coins) }}"
                     placeholder="500,1000,2500,5000">
              <small class="text-muted">Comma-separated gifted-coin milestones for this host only.</small>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label d-flex align-items-center justify-content-between">
          <span>Photos (max 6)</span>
          <small class="text-muted">Uploading new photos will replace existing set.</small>
        </label>
        <input type="file" name="photos[]" class="form-control" multiple accept="image/*">
        @if($host->photos->count())
          <div class="d-flex gap-2 mt-2 flex-wrap">
            @foreach($host->photos as $p)
              <div class="position-relative">
                <img src="{{ Storage::url($p->path) }}" class="rounded" style="width:96px;height:96px;object-fit:cover;">
              </div>
            @endforeach
          </div>
        @endif
        <small class="text-muted">Tip: upload up to six images; they will be ordered as selected.</small>
      </div>

      <div class="col-12">
        <label class="form-label">Bio</label>
        <textarea class="form-control" name="bio" rows="3">{{ old('bio',$host->bio) }}</textarea>
      </div>

      @if($host->followers->isNotEmpty())
        <div class="col-12">
          <label class="form-label">Recent Followers</label>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>User</th>
                  <th>Notify Online</th>
                  <th>Notify Available</th>
                  <th>Followed At</th>
                </tr>
              </thead>
              <tbody>
                @foreach($host->followers->sortByDesc('id')->take(10) as $follow)
                  <tr>
                    <td>
                      <div class="fw-semibold">{{ $follow->user?->name ?: 'User #'.$follow->user_id }}</div>
                      <div class="text-muted small">{{ $follow->user?->email }}</div>
                    </td>
                    <td>{{ $follow->notify_when_online ? 'Yes' : 'No' }}</td>
                    <td>{{ $follow->notify_when_available ? 'Yes' : 'No' }}</td>
                    <td>{{ optional($follow->created_at)->format('d M Y, H:i') }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      @endif
    </div>
    <div class="card-footer d-flex justify-content-between">
      <a href="{{ route('admin.hosts.index') }}" class="btn btn-light border">Back</a>
      <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save</button>
    </div>
  </div>
</form>
@endsection
