@extends('layouts.admin-berry')
@section('title','Subscription Plans')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Subscription Plans</h4>
  <a href="{{ route('admin.subscription-plans.create') }}" class="btn btn-primary">New Plan</a>
</div>

@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

<div class="card">
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr>
        <th>Name</th><th>Price (coins)</th><th>Duration (days)</th><th>Active</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($plans as $p)
          <tr>
            <td>{{ $p->name }}</td>
            <td>{{ $p->price_coins }}</td>
            <td>{{ $p->duration_days }}</td>
            <td>{!! $p->is_active ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' !!}</td>
            <td class="text-end">
              <a href="{{ route('admin.subscription-plans.edit',$p) }}" class="btn btn-sm btn-outline-primary">Edit</a>
              <form class="d-inline" method="post" action="{{ route('admin.subscription-plans.destroy',$p) }}">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete plan?')">Delete</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="card-footer">{{ $plans->links() }}</div>
</div>
@endsection
