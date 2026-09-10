@extends('layouts.admin-berry')
@section('title', 'Silent Watch · '.$live_room->room_id)

@section('content')
@php
  $roomType = $live_room->room_type ?? 'video';
@endphp

<style>
  .observer-stage {
    min-height: min(460px, calc(100svh - 230px));
    max-height: calc(100svh - 230px);
  }
  .observer-stage.is-active {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    align-content: start;
    gap: .75rem;
    overflow-y: auto;
    padding: .75rem;
  }
  .observer-track-tile {
    position: relative;
    min-height: 132px;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border-radius: 1rem;
    background: #111827;
  }
  .observer-track-media {
    height: 100%;
    width: 100%;
    object-fit: cover;
  }
  .observer-audio-tile {
    display: grid;
    place-items: center;
    min-height: 132px;
    border-radius: 1rem;
    background: linear-gradient(135deg, #111827, #312e81);
    color: #fff;
  }
  .observer-track-label {
    position: absolute;
    bottom: .5rem;
    left: .5rem;
    max-width: calc(100% - 1rem);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    border-radius: 9999px;
    background: rgb(0 0 0 / 60%);
    padding: .2rem .55rem;
    font-size: .7rem;
    font-weight: 700;
    color: #fff;
  }
  @media (max-width: 640px) {
    .observer-stage {
      min-height: min(340px, calc(100svh - 190px));
      max-height: calc(100svh - 190px);
    }
    .observer-stage.is-active {
      grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
      gap: .5rem;
      padding: .5rem;
    }
  }
</style>

<div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
  <div>
    <h4 class="mb-1">Silent Watch</h4>
    <div class="text-muted">
      Subscribe-only admin observer for
      <code>{{ $live_room->room_id }}</code>
      · {{ ucfirst($roomType) }} room
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-light border" href="{{ route('admin.live-rooms.show', $live_room) }}">Back to room</a>
    <button id="observerStart" type="button" class="btn btn-primary">
      <i class="ti ti-eye me-1"></i> Start silent watch
    </button>
    <button id="observerStop" type="button" class="btn btn-light border d-none">Stop</button>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card bg-dark border-0">
      <div id="observerStage" class="observer-stage d-grid place-items-center overflow-hidden rounded bg-black text-center text-secondary">
        <div id="observerEmpty" class="p-4 mx-auto" style="max-width: 380px;">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white bg-opacity-10 text-white mb-3" style="width: 58px; height: 58px;">
            <i class="ti ti-video fs-3"></i>
          </div>
          <div class="fw-semibold text-white">Ready to watch silently</div>
          <div class="mt-2 small text-secondary">
            This page joins LiveKit as a hidden subscribe-only admin observer. It does not publish camera, mic, data, chat, app presence, or join animation.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card mb-3">
      <div class="card-header">
        <h6 class="mb-0">Observer Status</h6>
      </div>
      <div class="card-body vstack gap-3 small">
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Connection</span>
          <span id="observerStatus" class="badge bg-secondary">Idle</span>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Visible to users</span>
          <span class="badge bg-success">No</span>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Can publish</span>
          <span class="badge bg-success">No</span>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Tracks</span>
          <strong id="observerTrackCount">0</strong>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h6 class="mb-0">Room</h6>
      </div>
      <div class="card-body vstack gap-3 small">
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Host</span>
          <strong class="text-end">{{ $live_room->host?->user?->name ?? '—' }}</strong>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Type</span>
          <strong>{{ ucfirst($roomType) }}</strong>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">LiveKit room</span>
          <code class="text-end">{{ $live_room->room_id }}</code>
        </div>
        <div class="d-flex justify-content-between gap-3">
          <span class="text-muted">Started</span>
          <strong class="text-end">{{ $live_room->started_at?->format('d M Y H:i') ?? '—' }}</strong>
        </div>
      </div>
    </div>
  </div>
</div>

<script
  src="https://cdn.jsdelivr.net/npm/livekit-client@2.5.1/dist/livekit-client.umd.min.js"
  integrity="sha384-33/ircfwMJCm1GLQ7AtQqMJP2NkDNfaPjfNrxlXLbdr/SFLrCTG7/EbQ6IA9g1Ul"
  crossorigin="anonymous"
></script>
<script>
(() => {
  const tokenUrl = @json(route('admin.live-rooms.observer-token', $live_room));
  const csrf = @json(csrf_token());
  const stage = document.getElementById('observerStage');
  const empty = document.getElementById('observerEmpty');
  const startButton = document.getElementById('observerStart');
  const stopButton = document.getElementById('observerStop');
  const statusNode = document.getElementById('observerStatus');
  const trackCountNode = document.getElementById('observerTrackCount');
  const trackElements = new Map();
  let room = null;
  let expiryTimer = null;
  let stopping = false;

  const setStatus = (value, tone = 'secondary') => {
    statusNode.textContent = value;
    statusNode.className = `badge bg-${tone}`;
  };

  const syncTrackCount = () => {
    trackCountNode.textContent = String(trackElements.size);
    empty?.classList.toggle('d-none', trackElements.size > 0);
    stage.classList.toggle('is-active', trackElements.size > 0);
  };

  const addTrack = (track, participant) => {
    if (!track || trackElements.has(track.sid)) return;

    const wrapper = document.createElement('div');
    wrapper.dataset.trackSid = track.sid;
    wrapper.className = track.kind === 'audio' ? 'observer-audio-tile position-relative' : 'observer-track-tile';

    const media = track.attach();
    media.autoplay = true;
    media.playsInline = true;
    media.className = track.kind === 'video' ? 'observer-track-media' : 'd-none';
    wrapper.appendChild(media);

    if (track.kind === 'audio') {
      const icon = document.createElement('i');
      icon.className = 'ti ti-volume fs-1 mb-2';
      const text = document.createElement('div');
      text.className = 'fw-semibold';
      text.textContent = 'Audio live';
      const holder = document.createElement('div');
      holder.className = 'text-center';
      holder.appendChild(icon);
      holder.appendChild(text);
      wrapper.appendChild(holder);
    }

    const label = document.createElement('div');
    label.className = 'observer-track-label';
    label.textContent = participant?.name || participant?.identity || 'Participant';
    wrapper.appendChild(label);

    stage.appendChild(wrapper);
    trackElements.set(track.sid, { track, wrapper });
    syncTrackCount();
  };

  const removeTrack = (track) => {
    if (!track || !trackElements.has(track.sid)) return;
    const item = trackElements.get(track.sid);
    track.detach().forEach((element) => element.remove());
    item.wrapper.remove();
    trackElements.delete(track.sid);
    syncTrackCount();
  };

  const attachExistingTracks = () => {
    room.remoteParticipants.forEach((participant) => {
      participant.trackPublications.forEach((publication) => {
        if (publication.track) addTrack(publication.track, participant);
      });
    });
  };

  const clearTracks = () => {
    trackElements.forEach(({ track, wrapper }) => {
      track.detach().forEach((element) => element.remove());
      wrapper.remove();
    });
    trackElements.clear();
    syncTrackCount();
  };

  const stop = async (status = 'Stopped', tone = 'secondary') => {
    if (stopping) return;
    stopping = true;
    if (expiryTimer) {
      window.clearTimeout(expiryTimer);
      expiryTimer = null;
    }

    const activeRoom = room;
    room = null;
    if (activeRoom) await activeRoom.disconnect();
    clearTracks();
    setStatus(status, tone);
    startButton.disabled = false;
    startButton.classList.remove('d-none');
    stopButton.classList.add('d-none');
    stopping = false;
  };

  startButton.addEventListener('click', async () => {
    if (!window.LivekitClient) {
      setStatus('LiveKit client failed to load', 'danger');
      return;
    }

    startButton.disabled = true;
    setStatus('Requesting token…', 'warning');

    try {
      const response = await fetch(tokenUrl, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Unable to start observer.');
      }

      const { Room, RoomEvent } = window.LivekitClient;
      room = new Room({
        adaptiveStream: true,
        dynacast: false,
      });

      room
        .on(RoomEvent.TrackSubscribed, (track, publication, participant) => addTrack(track, participant))
        .on(RoomEvent.TrackUnsubscribed, removeTrack)
        .on(RoomEvent.Disconnected, () => {
          if (!stopping) void stop('Disconnected', 'warning');
        });

      setStatus('Connecting…', 'warning');
      await room.connect(payload.ws_url, payload.token, { autoSubscribe: true });
      if (typeof room.startAudio === 'function') {
        await room.startAudio().catch(() => {});
      }
      attachExistingTracks();
      expiryTimer = window.setTimeout(
        () => void stop('Session expired', 'warning'),
        Math.max(1, Number(payload.expires_in || 900)) * 1000,
      );
      setStatus('Watching silently', 'success');
      startButton.classList.add('d-none');
      stopButton.classList.remove('d-none');
    } catch (error) {
      await stop(error?.message || 'Observer failed', 'danger');
    }
  });

  stopButton.addEventListener('click', () => void stop());
  window.addEventListener('beforeunload', () => {
    if (expiryTimer) window.clearTimeout(expiryTimer);
    if (room) void room.disconnect();
  });
})();
</script>
@endsection
