@extends('layouts.admin-berry')
@section('title','Edit Agency #'.$agency->id)
@section('content')
<form method="post" action="{{ route('admin.agencies.update',$agency) }}" class="vstack gap-3">
  @csrf @method('PUT')

  <div class="card">
    <div class="card-header">
      <h6 class="mb-0"><i class="ti ti-building me-2"></i>Edit Agency</h6>
    </div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Name</label>
        <input class="form-control" name="name" value="{{ old('name',$agency->name) }}">
      </div>
      <div class="col-md-3">
        <label class="form-label">Payout %</label>
        <input type="number" step="0.01" min="0" max="100" class="form-control" name="payout_percentage"
               value="{{ old('payout_percentage',$agency->payout_percentage) }}">
      </div>
      <div class="col-md-3">
        <label class="form-label">Weekly Bonus</label>
        <input type="number" min="0" class="form-control" name="weekly_bonus"
               value="{{ old('weekly_bonus',$agency->weekly_bonus) }}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Contact Email</label>
        <input type="email" class="form-control" name="contact_email" value="{{ old('contact_email',$agency->contact_email) }}">
      </div>
      <div class="col-md-6">
        <label class="form-label">Contact Phone</label>
        <input class="form-control" name="contact_phone" value="{{ old('contact_phone',$agency->contact_phone) }}">
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <textarea class="form-control" name="notes" rows="3">{{ old('notes',$agency->notes) }}</textarea>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <a href="{{ route('admin.agencies.index') }}" class="btn btn-light border">Back</a>
      <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save</button>
    </div>
  </div>
</form>
@endsection
