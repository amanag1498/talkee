@extends('layouts.admin-berry')
@section('title','Live Presence')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Live Presence</h4>
  <div class="d-flex align-items-center gap-3">
    <span class="badge bg-primary fs-6">
      <i class="ti ti-users me-1"></i> <span id="presenceCount">{{ $count }}</span> online
    </span>
    <small class="text-muted">Last update: <span id="presenceUpdated">{{ now()->format('H:i:s') }}</span></small>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <th style="width:100px;">User ID</th>
            <th>Name / Email</th>
            <th style="width:140px;">TTL</th>
          </tr>
        </thead>
        <tbody id="presenceBody">
          @forelse($rows as $r)
            <tr>
              <td>#{{ $r['user_id'] }}</td>
              <td>
                @if(!empty($r['user']))
                  <div class="fw-medium">{{ $r['user']['name'] ?? 'User' }}</div>
                  <div class="text-muted small">{{ $r['user']['email'] ?? '' }}</div>
                @else
                  <span class="text-muted">Unknown</span>
                @endif
              </td>
              <td>
                @php $ttl = $r['ttl_ms'] ?? null; @endphp
                <span class="badge {{ $ttl && $ttl < 10000 ? 'bg-warning' : 'bg-secondary' }}">
                  {{ $ttl ? ceil($ttl/1000).'s' : '—' }}
                </span>
              </td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-muted py-3">No one online right now.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

@push('scripts')
<script>
(function(){
  const countEl = document.getElementById('presenceCount');
  const bodyEl  = document.getElementById('presenceBody');
  const updEl   = document.getElementById('presenceUpdated');

  function esc(s){return (s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}

  async function refresh(){
    try{
      const res = await fetch(`{{ route('admin.presence.stats') }}?withUsers=1`, { headers:{'X-Requested-With':'XMLHttpRequest'} });
      if(!res.ok) return;
      const data = await res.json();

      countEl.textContent = data.count ?? 0;
      updEl.textContent = new Date().toLocaleTimeString();

      const rows = data.rows || [];
      if(!rows.length){
        bodyEl.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-3">No one online right now.</td></tr>`;
        return;
      }

      bodyEl.innerHTML = rows.map(r=>{
        const user=r.user||{};
        const ttl = r.ttl_ms ? Math.ceil(r.ttl_ms/1000)+'s' : '—';
        const badge = (r.ttl_ms && r.ttl_ms < 10000) ? 'bg-warning' : 'bg-secondary';
        return `
          <tr>
            <td>#${r.user_id}</td>
            <td>
              ${user.name ? `<div class="fw-medium">${esc(user.name)}</div>` : '<span class="text-muted">Unknown</span>'}
              ${user.email ? `<div class="text-muted small">${esc(user.email)}</div>` : ''}
            </td>
            <td><span class="badge ${badge}">${ttl}</span></td>
          </tr>
        `;
      }).join('');
    }catch(e){}
  }
  setInterval(refresh, 5000);
})();
</script>
@endpush
@endsection
