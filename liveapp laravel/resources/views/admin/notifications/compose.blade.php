@extends('layouts.admin-berry')
@section('title','Notifications · Compose')

@section('content')
@php
  $aud = old('audience', request('audience','user')); // 'user' | 'role' | 'all'
@endphp

<div class="card">
  <div class="card-body">
    @if(session('ok')) <div class="alert alert-success">{{ session('ok') }}</div>@endif
    @if($errors->any())
      <div class="alert alert-danger">
        <ul class="mb-0">
          @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
      </div>
    @endif

    <form method="post" action="{{ route('admin.notifications.send') }}" class="row g-3">
      @csrf

      <div class="col-md-3">
        <label class="form-label">Audience</label>
        <select name="audience" class="form-select" id="audienceSel" data-initial="{{ $aud }}">
          <option value="user" {{ $aud === 'user' ? 'selected' : '' }}>Single user</option>
          <option value="role" {{ $aud === 'role' ? 'selected' : '' }}>Role</option>
          <option value="all"  {{ $aud === 'all'  ? 'selected' : '' }}>All users</option>
        </select>
      </div>

      <div class="col-md-3 audience-user {{ $aud === 'user' ? '' : 'd-none' }}">
        <label class="form-label">User ID</label>
        <input type="number" class="form-control" name="user_id" placeholder="e.g. 15"
               value="{{ old('user_id', request('user_id')) }}">
      </div>

      <div class="col-md-3 audience-role {{ $aud === 'role' ? '' : 'd-none' }}">
        <label class="form-label">Role</label>
        <input type="text" class="form-control" name="role" placeholder="e.g. host"
               value="{{ old('role', request('role')) }}">
      </div>

      <div class="col-md-3">
        <label class="form-label">Type (optional)</label>
        <input type="text" class="form-control" name="type" placeholder="host_approved"
               value="{{ old('type', request('type')) }}">
      </div>

      <div class="col-12">
        <label class="form-label">Title</label>
        <input type="text" class="form-control" name="title" required
               value="{{ old('title', request('title')) }}">
      </div>

      <div class="col-12">
        <label class="form-label">Body</label>
        <textarea class="form-control" name="body" rows="3">{{ old('body', request('body')) }}</textarea>
      </div>

      <div class="col-md-4">
        <label class="form-label">Deep-link screen (optional)</label>
        <input type="text" class="form-control" name="screen" placeholder="notifications | room"
               value="{{ old('screen', request('screen')) }}">
        <div class="form-text">Use <code>notifications</code> to open the inbox or <code>room</code> to deep-link to a room.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Room ID (if screen = room)</label>
        <input type="text" class="form-control" name="room_id" placeholder="abc123"
               value="{{ old('room_id', request('room_id')) }}">
      </div>

      <div class="col-12">
        <label class="form-label">Meta (JSON, optional)</label>
        <textarea class="form-control" name="meta" rows="3" placeholder='{"foo":"bar"}'>{{ old('meta', request('meta')) }}</textarea>
      </div>

      <div class="col-12">
        {{-- Hidden fallbacks so unchecked => 0 --}}
        <input type="hidden" name="persist" value="0">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" name="persist" id="persistChk" value="1"
                 {{ old('persist', request('persist','1')) == '1' ? 'checked' : '' }}>
          <label class="form-check-label" for="persistChk">Store in user notifications (DB)</label>
        </div>

        <input type="hidden" name="push" value="0">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" name="push" id="pushChk" value="1"
                 {{ old('push', request('push','1')) == '1' ? 'checked' : '' }}>
          <label class="form-check-label" for="pushChk">Send Firebase push</label>
        </div>
      </div>

      <div class="col-12">
        <button class="btn btn-primary">
          <i class="ti ti-send me-1"></i> Send
        </button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script>
  (function () {
    const sel  = document.getElementById('audienceSel');
    const user = document.querySelector('.audience-user');
    const role = document.querySelector('.audience-role');
    if (!sel || !user || !role) return;

    function sync() {
      user.classList.toggle('d-none', sel.value !== 'user');
      role.classList.toggle('d-none', sel.value !== 'role');
    }

    const initial = sel.dataset.initial || sel.value || 'user';
    sel.value = initial;
    sync();

    sel.addEventListener('change', sync);
  })();
</script>
@endpush
