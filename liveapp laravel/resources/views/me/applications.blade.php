@extends('layouts.app')
@section('title','My Applications')

@section('content')
  <h3 class="mb-3">My Applications</h3>

  <div class="row g-4">
    <div class="col-12">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Agency Applications</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Agency</th><th>Status</th><th>Reviewed</th><th>Updated</th></tr></thead>
            <tbody>
              @forelse($agencyRequests as $r)
                <tr>
                  <td>{{ $r->id }}</td>
                  <td>{{ $r->agency_name }}</td>
                  <td>
                    <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                      {{ ucfirst($r->status) }}
                    </span>
                  </td>
                  <td>{{ $r->reviewed_at?->format('d M Y H:i') ?? '—' }}</td>
                  <td>{{ $r->updated_at->format('d M Y H:i') }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No agency applications yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Host Applications</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Stage Name</th><th>Status</th><th>Reviewed</th><th>Updated</th></tr></thead>
            <tbody>
              @forelse($hostRequests as $r)
                <tr>
                  <td>{{ $r->id }}</td>
                  <td>{{ $r->stage_name ?: '—' }}</td>
                  <td>
                    <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                      {{ ucfirst($r->status) }}
                    </span>
                  </td>
                  <td>{{ $r->reviewed_at?->format('d M Y H:i') ?? '—' }}</td>
                  <td>{{ $r->updated_at->format('d M Y H:i') }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No host applications yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header"><h5 class="mb-0">Host → Agency Enroll Requests</h5></div>
        <div class="card-body table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Agency</th><th>Status</th><th>Reviewed</th><th>Updated</th></tr></thead>
            <tbody>
              @forelse($enrollRequests as $r)
                <tr>
                  <td>{{ $r->id }}</td>
                  <td>{{ $r->agency?->name }}</td>
                  <td>
                    <span class="badge {{ $r->status==='pending'?'bg-warning text-dark':($r->status==='approved'?'bg-success':'bg-danger') }}">
                      {{ ucfirst($r->status) }}
                    </span>
                  </td>
                  <td>{{ $r->reviewed_at?->format('d M Y H:i') ?? '—' }}</td>
                  <td>{{ $r->updated_at->format('d M Y H:i') }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted py-3">No enroll requests yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
@endsection
