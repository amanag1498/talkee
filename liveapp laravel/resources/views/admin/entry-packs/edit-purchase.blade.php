@extends('layouts.admin-berry')
@section('title','Edit User Entry Pack')

@section('content')
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-user-star me-2"></i>Edit User Entry Pack</h6>
      </div>
      <div class="card-body">
        @if($errors->any())
          <div class="alert alert-danger">
            <div class="fw-semibold mb-1">Please fix the following:</div>
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
          </div>
        @endif
        <form method="post" action="{{ route('admin.entry-packs.purchases.update', $userPack) }}" class="vstack gap-3">
          @csrf
          @method('PUT')
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">User</label>
              <input class="form-control" value="{{ $userPack->user?->name }} @ {{ $userPack->user?->email }}" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Entry Pack</label>
              <select name="entry_pack_id" class="form-select">
                @foreach($packs as $pack)
                  <option value="{{ $pack->id }}" @selected((int) old('entry_pack_id', $userPack->entry_pack_id) === (int) $pack->id)>{{ $pack->name }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Purchased At</label>
              <input type="datetime-local" name="purchased_at" class="form-control" value="{{ old('purchased_at', optional($userPack->purchased_at)->format('Y-m-d\TH:i')) }}">
            </div>
            <div class="col-md-6">
              <label class="form-label">Expires At</label>
              <input type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at', optional($userPack->expires_at)->format('Y-m-d\TH:i')) }}">
            </div>
          </div>
          <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', (int) $userPack->is_active) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active for this user</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary">Update</button>
            <a class="btn btn-light border" href="{{ route('admin.entry-packs.reports') }}">Back</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection
