@extends('layouts.admin-berry')
@section('title','Edit Subscription Plan')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Edit Plan: {{ $plan->name }}</h4>
  <a href="{{ route('admin.subscription-plans.index') }}" class="btn btn-outline-secondary">
    <i class="ti ti-arrow-left me-1"></i> Back
  </a>
</div>

@if(session('success')) 
  <div class="alert alert-success">{{ session('success') }}</div> 
@endif

@if($errors->any())
  <div class="alert alert-danger">
    <strong>Whoops!</strong> Please fix the errors below.
  </div>
@endif

<div class="card">
  <div class="card-body">
    <form id="planForm" method="post" action="{{ route('admin.subscription-plans.update', $plan) }}" class="vstack gap-3">
      @csrf
      @method('PUT')

      <div>
        <label class="form-label">Name</label>
        <input name="name" class="form-control @error('name') is-invalid @enderror" 
               value="{{ old('name', $plan->name) }}" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
      </div>

      <div class="row">
        <div class="col">
          <label class="form-label">Price (coins)</label>
          <input type="number" name="price_coins" min="1" 
                 class="form-control @error('price_coins') is-invalid @enderror"
                 value="{{ old('price_coins', $plan->price_coins) }}" required>
          @error('price_coins') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="col">
          <label class="form-label">Duration (days)</label>
          <input type="number" name="duration_days" min="1" 
                 class="form-control @error('duration_days') is-invalid @enderror"
                 value="{{ old('duration_days', $plan->duration_days) }}" required>
          @error('duration_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
      </div>

      <div>
        <label class="form-label d-flex align-items-center gap-2">
          Perks (JSON) 
          <small class="text-muted">Optional – Example: {"badge":"Pro","limits":{"daily":5}}</small>
        </label>
        <textarea name="perks" id="perks"
                  class="form-control @error('perks') is-invalid @enderror" rows="6"
                  placeholder='{"badge":"Pro","limits":{"daily":5}}'>{{ 
                    old('perks', $plan->perks ? json_encode($plan->perks, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '') 
                  }}</textarea>
        @error('perks') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div id="perksError" class="text-danger small mt-1" style="display:none;"></div>
      </div>

      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
               value="1" {{ old('is_active', $plan->is_active) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary" id="saveBtn">
          <i class="ti ti-device-floppy me-1"></i> Save changes
        </button>
        <a href="{{ route('admin.subscription-plans.index') }}" class="btn btn-light">Cancel</a>
      </div>
    </form>
  </div>
</div>

{{-- Tiny JSON validator for perks --}}
@push('scripts')
<script>
  document.getElementById('planForm').addEventListener('submit', function(e) {
    const perksEl = document.getElementById('perks');
    const errEl = document.getElementById('perksError');
    errEl.style.display = 'none'; errEl.textContent = '';
    const val = perksEl.value.trim();
    if (val.length) {
      try { JSON.parse(val); } 
      catch (ex) {
        e.preventDefault();
        errEl.textContent = 'Perks must be valid JSON.';
        errEl.style.display = 'block';
      }
    }
  });
</script>
@endpush
@endsection
