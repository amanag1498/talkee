@extends('layouts.admin-berry')

@section('title','Agency Requests')
@section('content')

<div class="card">
  <div class="card-header"><h5>Agency Requests</h5></div>
  <div class="card-body table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>User</th><th>Agency</th><th>Status</th><th>Applied</th><th></th></tr></thead>
      <tbody>
      @foreach($requests as $r)
        <tr>
          <td>{{ $r->id }}</td>
          <td>{{ $r->user?->name }}<br><small>{{ $r->user?->email }}</small></td>
          <td>{{ $r->agency_name }}</td>
          <td><span class="badge bg-{{ $r->status==='pending'?'warning':($r->status==='approved'?'success':'danger') }}">{{ $r->status }}</span></td>
          <td>{{ $r->created_at->format('d M Y') }}</td>
          <td><a class="btn btn-sm btn-primary" href="{{ route('admin.agency-requests.show',$r) }}">Review</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    {{ $requests->links() }}
  </div>
</div>
@endsection
