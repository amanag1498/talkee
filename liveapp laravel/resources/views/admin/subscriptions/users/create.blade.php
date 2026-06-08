@extends('layouts.admin-berry')
@section('title','Create User Subscription')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Create User Subscription</h4>
  <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card">
  <div class="card-body">
    <form method="post" action="{{ route('admin.user-subscriptions.store') }}" class="vstack gap-3">
      @csrf

      <div class="row">
        <div class="col-md-6">
          <label class="form-label">User</label>
          <select name="user_id" class="form-select" required>
            @foreach($users as $u)
              <option value="{{ $u->id }}">{{ $u->id }} — {{ $u->name }} ({{ $u->email }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Plan</label>
          <select name="plan_id" class="form-select" required>
            @foreach($plans as $p)
              <option value="{{ $p->id }}">{{ $p->name }} — {{ $p->price_coins }} coins / {{ $p->duration_days }}d</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="row">
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active">active</option>
            <option value="cancelled">cancelled</option>
            <option value="expired">expired</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Starts at (optional)</label>
          <input type="datetime-local" name="starts_at" class="form-control">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ends at (optional)</label>
          <input type="datetime-local" name="ends_at" class="form-control">
        </div>
      </div>

      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="charge_coins" name="charge_coins" value="1">
        <label class="form-check-label" for="charge_coins">Charge coins from user wallet now</label>
      </div>

      <div class="row">
        <div class="col-md-4">
          <label class="form-label">Source when not charging</label>
          <select name="source_type" class="form-select">
            <option value="admin_grant">Admin grant</option>
            <option value="signup_gift">Signup gift</option>
            <option value="gift">Gift / promotion</option>
          </select>
          <div class="form-text">If “Charge coins” is checked, this will be recorded as Admin Charged.</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Admin note</label>
          <input type="text" name="meta[note]" class="form-control" maxlength="500" placeholder="Reason or context visible in admin trace">
        </div>
      </div>

      <div>
        <button class="btn btn-primary">Create</button>
        <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-light">Cancel</a>
      </div>
    </form>
  </div>
</div>
@endsection
