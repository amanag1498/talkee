@extends('layouts.admin-berry')
@section('title','New Subscription Plan')
@section('content')
<div class="card">
  <div class="card-body">
    <form method="post" action="{{ route('admin.subscription-plans.store') }}" class="vstack gap-3">
      @csrf
      <div>
        <label class="form-label">Name</label>
        <input name="name" class="form-control" required>
      </div>
      <div class="row">
        <div class="col">
          <label class="form-label">Price (coins)</label>
          <input type="number" name="price_coins" class="form-control" min="1" required>
        </div>
        <div class="col">
          <label class="form-label">Duration (days)</label>
          <input type="number" name="duration_days" class="form-control" min="1" required>
        </div>
      </div>
      <div>
        <label class="form-label">Perks (JSON)</label>
        <textarea name="perks" class="form-control" rows="3" placeholder='{"badge":"Pro","limits":{"daily":5}}'></textarea>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="is_active">
        <label class="form-check-label" for="is_active">Active</label>
      </div>
      <button class="btn btn-primary">Create</button>
    </form>
  </div>
</div>
@endsection
