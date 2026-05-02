@extends('layouts.admin-berry')
@section('title','Notifications · Recent')

@section('content')

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><i class="ti ti-bell me-2"></i>Recent Notifications</h5>
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
      <div>
        <label class="form-label mb-1">User ID</label>
        <input class="form-control" name="user_id" value="{{ request('user_id') }}" placeholder="User ID" style="width:120px">
      </div>

      <div>
        <label class="form-label mb-1">Type</label>
        <input class="form-control" name="type" value="{{ request('type') }}" placeholder="Type" list="typeList" style="width:160px">
        @isset($types)
          <datalist id="typeList">
            @foreach($types as $t) <option value="{{ $t }}">{{ $t }}</option> @endforeach
          </datalist>
        @endisset
      </div>

      <div class="form-check ms-2">
        <input class="form-check-input" type="checkbox" id="unreadOnly" name="unread" value="1" {{ request('unread') ? 'checked' : '' }}>
        <label class="form-check-label" for="unreadOnly">Unread only</label>
      </div>

      <div>
        <label class="form-label mb-1">From</label>
        <input type="date" class="form-control" name="created_from" value="{{ request('created_from') }}">
      </div>
      <div>
        <label class="form-label mb-1">To</label>
        <input type="date" class="form-control" name="created_to" value="{{ request('created_to') }}">
      </div>

      <button class="btn btn-light border ms-2">Filter</button>
      <a class="btn btn-outline-secondary" href="{{ route('admin.notifications.index') }}">Reset</a>
      <a class="btn btn-primary ms-auto" href="{{ route('admin.notifications.compose') }}">
        <i class="ti ti-send me-1"></i> Compose
      </a>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th><th>User</th><th>Title</th><th>Type</th><th>Read</th><th>Created</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @foreach($items as $n)
          <tr>
            <td>{{ $n->id }}</td>
            <td>
              <a href="{{ route('admin.users.notifications',$n->user_id) }}">
                User #{{ $n->user_id }}
              </a>
            </td>
            <td>{{ $n->title }}</td>
            <td><code>{{ $n->type ?? '—' }}</code></td>
            <td>
              @if($n->read_at)
                <span class="badge bg-success">Read</span>
              @else
                <span class="badge bg-secondary">Unread</span>
              @endif
            </td>
            <td>{{ $n->created_at?->format('Y-m-d H:i') }}</td>
            <td class="text-end">
              {{-- Resend shortcut: prefill compose form via query string --}}
              <a class="btn btn-sm btn-outline-primary"
                 href="{{ route('admin.notifications.compose', [
                      'audience' => 'user',
                      'user_id'  => $n->user_id,
                      'type'     => $n->type,
                      'title'    => $n->title,
                      'body'     => $n->body,
                      'meta'     => is_array($n->meta) ? json_encode($n->meta) : $n->meta,
                  ]) }}">
                Resend
              </a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex justify-content-end">
    {{ $items->withQueryString()->links() }}
  </div>
</div>
@endsection
