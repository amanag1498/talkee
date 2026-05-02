@extends('layouts.admin-berry')
@section('title','Review Agency #'.$agency_request->id)

@section('content')
{{-- Header row: back + title + status --}}
<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="d-flex align-items-center gap-2">
    <a href="{{ route('admin.agency-requests.index') }}" class="btn btn-light border">
      <i class="ti ti-arrow-left"></i>
    </a>
    <div>
      <h5 class="mb-0">Agency Request #{{ $agency_request->id }}</h5>
      <small class="text-muted">Submitted {{ $agency_request->created_at?->diffForHumans() }}</small>
    </div>
  </div>
  <span class="badge rounded-pill
    {{ $agency_request->status==='pending' ? 'bg-warning text-dark' : ($agency_request->status==='approved' ? 'bg-success' : 'bg-danger') }}">
    {{ ucfirst($agency_request->status) }}
  </span>
</div>

<div class="row g-3">
  {{-- LEFT: Applicant & Application details --}}
  <div class="col-lg-8">
    {{-- Applicant card --}}
    <div class="card mb-3">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-user me-2"></i>Applicant</h6>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <img src="{{ asset('berry/assets/images/user/avatar-2.jpg') }}" class="rounded-circle" width="56" height="56" alt="avatar">
          <div class="flex-fill">
            <div class="d-flex align-items-center gap-2">
              <span class="fw-semibold">{{ $agency_request->user?->name ?? '—' }}</span>
              @if($agency_request->user?->email_verified_at)
                <span class="badge bg-light-success text-success">Verified</span>
              @endif
            </div>
            <div class="text-muted small">
              <i class="ti ti-mail me-1"></i>{{ $agency_request->user?->email ?? '—' }}
            </div>
            <div class="text-muted small">
              <i class="ti ti-hash me-1"></i>User ID: {{ $agency_request->user?->id ?? '—' }}
            </div>
          </div>
        </div>
      </div>
    </div>

    {{-- Application details --}}
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-building me-2"></i>Application Details</h6>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-muted small mb-1">Agency Name</div>
            <div class="fw-medium">{{ $agency_request->agency_name }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small mb-1">Legal Name</div>
            <div class="fw-medium">{{ $agency_request->legal_name ?: '—' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small mb-1">Phone</div>
            <div class="fw-medium">{{ $agency_request->contact_phone ?: '—' }}</div>
          </div>
          <div class="col-md-6">
            <div class="text-muted small mb-1">Website</div>
            <div class="fw-medium">
              @if($agency_request->website)
                <a href="{{ $agency_request->website }}" target="_blank">{{ $agency_request->website }}</a>
              @else
                —
              @endif
            </div>
          </div>
          <div class="col-12">
            <div class="text-muted small mb-1">About</div>
            <div class="border rounded p-3 bg-light text-break" style="min-height: 72px;">
              {!! nl2br(e($agency_request->about ?? '—')) !!}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- RIGHT: Review panel (sticky) --}}
  <div class="col-lg-4">
    <div class="card sticky-top" style="top: 90px;">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-clipboard-check me-2"></i>Review</h6>
      </div>
      <div class="card-body">
        <ul class="list-group list-group-flush mb-3">
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Applied</span>
            <span class="fw-medium">{{ $agency_request->created_at?->format('d M Y, H:i') ?? '—' }}</span>
          </li>
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Current Status</span>
            <span class="fw-medium">{{ ucfirst($agency_request->status) }}</span>
          </li>
          @if($agency_request->reviewed_at)
          <li class="list-group-item px-0 d-flex justify-content-between">
            <span class="text-muted">Reviewed</span>
            <span class="fw-medium">{{ $agency_request->reviewed_at?->format('d M Y, H:i') }}</span>
          </li>
          @endif
        </ul>

        @if($agency_request->status==='pending')
          <form method="post" action="{{ route('admin.agency-requests.update',$agency_request) }}" class="vstack gap-2">
            @csrf
            @method('PUT')
            <input type="hidden" name="action" value="approve" id="actionField">

            <label class="form-label small text-muted mb-1">Review notes (optional)</label>
            <textarea name="notes" rows="3" class="form-control" placeholder="Notes for audit trail"></textarea>

            <div class="d-grid gap-2 mt-2">
              <button class="btn btn-success" onclick="document.getElementById('actionField').value='approve'">
                <i class="ti ti-check me-1"></i> Approve
              </button>
              <button type="submit" class="btn btn-danger" onclick="document.getElementById('actionField').value='reject'">
                <i class="ti ti-x me-1"></i> Reject
              </button>
              <a href="{{ route('admin.agency-requests.index') }}" class="btn btn-light border">Back to list</a>
            </div>
          </form>
        @else
          <div class="alert alert-info mb-3">
            This request is already <strong>{{ $agency_request->status }}</strong>.
          </div>
          <a href="{{ route('admin.agency-requests.index') }}" class="btn btn-light border w-100">Back to list</a>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
