@extends('layouts.admin-berry')
@section('title','User Subscriptions')

@section('content')
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error'))   <div class="alert alert-danger">{{ session('error') }}</div>   @endif

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">User Subscriptions</h4>
  <a href="{{ route('admin.user-subscriptions.create') }}" class="btn btn-primary">
    <i class="ti ti-plus me-1"></i> Create Subscription
  </a>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Active</div><div class="h4 mb-0">{{ number_format($summary['active'] ?? 0) }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Expired</div><div class="h4 mb-0">{{ number_format($summary['expired'] ?? 0) }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Gifted</div><div class="h4 mb-0">{{ number_format($summary['gifted'] ?? 0) }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Renewal Rate</div><div class="h4 mb-0">{{ number_format($summary['renewal_rate'] ?? 0, 1) }}%</div></div></div></div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead>
        <tr>
          <th>User</th>
          <th>Plan</th>
          <th>Status</th>
          <th>Starts</th>
          <th>Ends</th>
          <th>Last Purchase</th>
          <th>Meta</th> {{-- NEW --}}
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse($subs as $s)
          @php
            /** @var array|null $meta */
            $meta = is_array($s->meta ?? null) ? $s->meta : (is_string($s->meta ?? null) ? json_decode($s->meta, true) : []);
            $meta = is_array($meta) ? $meta : [];
            $rowId = 'metaModal-' . $s->id;
            // Convenience getters
            $m = fn($key, $default=null) => $meta[$key] ?? $default;
            $boolBadge = function($value) {
              if ($value === true || $value === 'true' || $value === 1 || $value === '1') return '<span class="badge bg-success">true</span>';
              if ($value === false || $value === 'false' || $value === 0 || $value === '0') return '<span class="badge bg-secondary">false</span>';
              return '';
            };
          @endphp

          <tr>
            <td><a href="{{ route('admin.users.show', $s->user) }}">{{ $s->user->name }}</a> (#{{ $s->user->id }})</td>
            <td>{{ $s->plan->name ?? '—' }}</td>
            <td>
              <span class="badge bg-{{ $s->status==='active' ? 'success' : ($s->status==='cancelled' ? 'warning' : 'secondary') }}">
                {{ $s->status }}
              </span>
            </td>
            <td>{{ $s->starts_at?->format('Y-m-d H:i') }}</td>
            <td>{{ $s->ends_at?->format('Y-m-d H:i') }}</td>
            <td>{{ $s->last_purchased_at?->format('Y-m-d H:i') }}</td>

            {{-- META SUMMARY CELL --}}
            {{-- META SUMMARY CELL: line-by-line --}}
<td style="min-width: 360px; max-width: 520px;">
  @php
    // Normalize meta to array
    $metaRaw = $s->meta ?? null;
    $metaArr = is_array($metaRaw)
      ? $metaRaw
      : (is_string($metaRaw) ? json_decode($metaRaw, true) : []);
    $metaArr = is_array($metaArr) ? $metaArr : [];

    // Flattener: builds dot.notation => value lines
    $lines = [];
    $flatten = function ($data, $prefix = '') use (&$flatten, &$lines) {
      if (is_array($data)) {
        foreach ($data as $k => $v) {
          $key = $prefix === '' ? (string)$k : "{$prefix}.{$k}";
          $flatten($v, $key);
        }
      } else {
        // Scalars & objects: stringify
        if (is_bool($data)) {
          $val = $data ? 'true' : 'false';
        } elseif ($data === null) {
          $val = 'null';
        } elseif (is_object($data)) {
          // If object sneaks in, dump as JSON
          $val = json_encode($data, JSON_UNESCAPED_SLASHES);
        } else {
          $val = (string)$data;
        }
        $lines[] = [$prefix, $val];
      }
    };
    $flatten($metaArr);

    // Small helper to trim long values
    $limit = function (string $v, int $len = 180) {
      return \Illuminate\Support\Str::limit($v, $len);
    };
  @endphp

  @if(empty($metaArr))
    <span class="text-muted">—</span>
  @else
    <div class="border rounded p-2 bg-light" style="max-height: 180px; overflow:auto;">
      <div class="small">
        @foreach($lines as [$k, $v])
          <div class="d-flex">
            <div class="text-muted me-2" style="min-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
              {{ $k }}
            </div>
            <div class="flex-grow-1" style="word-break: break-word;">
              : {{ $limit($v) }}
            </div>
          </div>
        @endforeach
      </div>
    </div>

    {{-- Optional: tiny copy button for full JSON (kept here for convenience) --}}
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
            onclick="navigator.clipboard.writeText(this.nextElementSibling.textContent.trim()); this.innerText='Copied!'; setTimeout(()=>this.innerText='Copy JSON',1200);">
      Copy JSON
    </button>
    <pre class="visually-hidden">{{ json_encode($metaArr, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
  @endif
</td>


            <td class="text-end">
              <div class="btn-group">
                <a href="{{ route('admin.user-subscriptions.edit', $s) }}" class="btn btn-sm btn-outline-primary">
                  <i class="ti ti-edit"></i> Edit
                </a>
                <a href="{{ route('admin.users.show', $s->user) }}" class="btn btn-sm btn-outline-secondary ms-1">
                  <i class="ti ti-user-circle"></i> Profile
                </a>

                @if($s->status === 'active')
                  <form method="post" action="{{ route('admin.user-subscriptions.cancel', $s->id) }}" class="ms-1">
                    @csrf
                    <button class="btn btn-sm btn-outline-warning"
                            onclick="return confirm('Cancel this subscription now?')">
                      <i class="ti ti-ban"></i> Cancel
                    </button>
                  </form>
                @endif

                <form method="post" action="{{ route('admin.user-subscriptions.destroy', $s) }}" class="ms-1">
                  @csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger"
                          onclick="return confirm('Delete this subscription? This cannot be undone.')">
                    <i class="ti ti-trash"></i> Delete
                  </button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No subscriptions yet.</td> {{-- colspan +1 because of Meta --}}
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-footer">
    {{ $subs->links() }}
  </div>
</div>
@endsection
