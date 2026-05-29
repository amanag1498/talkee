@extends('layouts.admin-berry')
@section('title','Agencies')
@section('content')
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="ti ti-building me-2"></i>Agencies</h5>
    <form class="d-flex gap-2" method="get">
      <input class="form-control" name="s" value="{{ request('s') }}" placeholder="Search name/owner">
      <button class="btn btn-light border">Search</button>
    </form>
  </div>
  <div class="card-body table-responsive">
    <table class="table align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Owner</th>
          <th>Phone</th>
          <th>Payout %</th>
          <th>Weekly Bonus</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
      @foreach($agencies as $a)
        <tr>
          <td>{{ $a->id }}</td>
          <td>{{ $a->name }}</td>
          <td>
            <div class="d-flex flex-column">
              <span class="fw-medium">{{ $a->owner?->name ?? '—' }}</span>
              <small class="text-muted">{{ $a->owner?->email ?? '' }}</small>
            </div>
          </td>
          <td>{{ $a->contact_phone ?? '—' }}</td>
          <td>{{ number_format((float)$a->payout_percentage, 2) }}%</td>
          <td>{{ $a->weekly_bonus ? number_format($a->weekly_bonus) : '0' }}</td>
          <td>
            @if($a->is_blocked)
              <span class="badge bg-danger">Blocked</span>
            @else
              <span class="badge bg-success">Active</span>
            @endif
          </td>
          <td class="text-end">
            <a href="{{ route('admin.agencies.dashboard',$a) }}" class="btn btn-sm btn-light border me-2">
              <i class="ti ti-layout-dashboard me-1"></i>Dashboard
            </a>
            <a href="{{ route('admin.agencies.wallet.show',$a) }}" class="btn btn-sm btn-light border me-2">
              <i class="ti ti-wallet me-1"></i>Wallet
            </a>
            <a href="{{ route('admin.agencies.edit',$a) }}" class="btn btn-sm btn-primary me-2">
              <i class="ti ti-edit me-1"></i>Edit
            </a>
            @if($a->is_blocked)
              <form method="post" action="{{ route('admin.agencies.unblock',$a) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-success"><i class="ti ti-lock-open me-1"></i>Unblock</button>
              </form>
            @else
              <form method="post" action="{{ route('admin.agencies.block',$a) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-danger"><i class="ti ti-lock me-1"></i>Block</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <div class="d-flex justify-content-end">{{ $agencies->links() }}</div>
  </div>
</div>
@endsection
