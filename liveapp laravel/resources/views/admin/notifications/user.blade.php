@extends('layouts.admin-berry')
@section('title','User Notifications')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">
    <i class="ti ti-user me-2"></i>User #{{ $user->id }} — {{ $user->name }}
  </h5>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="{{ route('admin.notifications.index') }}">All notifications</a>
    <a class="btn btn-primary" href="{{ route('admin.notifications.compose', ['audience'=>'user','user_id'=>$user->id]) }}">
      <i class="ti ti-send me-1"></i> Send Notification
    </a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
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
      <a class="btn btn-outline-secondary" href="{{ route('admin.users.notifications', $user->id) }}">Reset</a>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Title</th><th>Type</th><th>Body</th><th>Read</th><th>Created</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $n)
          <tr>
            <td>{{ $n->id }}</td>
            <td>{{ $n->title }}</td>
            <td><code>{{ $n->type ?? '—' }}</code></td>
            <td class="text-muted">{{ \Illuminate\Support\Str::limit($n->body, 80) }}</td>
            <td>
              @if($n->read_at) <span class="badge bg-success">Read</span>
              @else <span class="badge bg-secondary">Unread</span> @endif
            </td>
            <td>{{ $n->created_at?->format('Y-m-d H:i') }}</td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary"
                 href="{{ route('admin.notifications.compose', [
                      'audience' => 'user',
                      'user_id'  => $user->id,
                      'type'     => $n->type,
                      'title'    => $n->title,
                      'body'     => $n->body,
                      'meta'     => is_array($n->meta) ? json_encode($n->meta) : $n->meta,
                  ]) }}">
                Resend
              </a>
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">No notifications.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex justify-content-end">
    {{ $items->links() }}
  </div>
</div>
@endsection
