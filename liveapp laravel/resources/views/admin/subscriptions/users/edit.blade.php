@extends('layouts.admin-berry')
@section('title','Edit User Subscription')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Edit Subscription #{{ $sub->id }} — {{ $sub->user->name }}</h4>
  <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card">
  <div class="card-body">
    @php
      $meta = is_array($sub->meta ?? null) ? $sub->meta : [];
    @endphp
    <div class="alert alert-light border d-flex flex-wrap gap-2 align-items-center">
      <span class="fw-semibold">Current source:</span>
      <span class="badge {{ $sub->origin_badge_class }}">{{ $sub->origin_label }}</span>
      <span class="text-muted small">{{ $sub->origin_description }}</span>
    </div>

    {{-- UPDATE FORM (no submit button inside) --}}
    <form id="update-sub" method="POST"
          action="{{ route('admin.user-subscriptions.update', $sub) }}"
          class="vstack gap-3">
      @csrf
      @method('PUT')

      <div class="row">
        <div class="col-md-6">
          <label class="form-label">User</label>
          <input class="form-control"
                 value="#{{ $sub->user->id }} — {{ $sub->user->name }} ({{ $sub->user->email }})"
                 disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label">Plan</label>
          <select name="plan_id" class="form-select" required>
            @foreach($plans as $p)
              <option value="{{ $p->id }}" {{ $sub->subscription_plan_id==$p->id?'selected':'' }}>
                {{ $p->name }} — {{ $p->price_coins }} coins / {{ $p->duration_days }}d
              </option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="row">
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            @foreach(['active','cancelled','expired'] as $s)
              <option value="{{ $s }}" {{ $sub->status===$s?'selected':'' }}>{{ $s }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Starts at</label>
          <input type="datetime-local" name="starts_at" class="form-control"
                 value="{{ $sub->starts_at?->format('Y-m-d\TH:i') }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ends at</label>
          <input type="datetime-local" name="ends_at" class="form-control"
                 value="{{ $sub->ends_at?->format('Y-m-d\TH:i') }}">
        </div>
      </div>

      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="charge_coins" name="charge_coins" value="1">
        <label class="form-check-label" for="charge_coins">Charge coins from user wallet now</label>
      </div>

      <div class="row">
        <div class="col-md-4">
          <label class="form-label">Source correction</label>
          <select name="meta[source]" class="form-select">
            @foreach([
              'USER_PURCHASE' => 'Purchased by user',
              'signup_gift' => 'Signup gift',
              'gift' => 'Gift / promotion',
              'admin_grant' => 'Admin grant',
              'admin_charged' => 'Admin charged',
              'admin' => 'Legacy admin',
              'admin_user_360' => 'Legacy user 360',
              'other' => 'Other',
            ] as $value => $label)
              <option value="{{ $value }}" @selected(($meta['source'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <div class="form-text">Use this to fix legacy records that are unclear.</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Admin note</label>
          <input type="text" name="meta[note]" class="form-control" maxlength="500" value="{{ $meta['note'] ?? $meta['reason'] ?? '' }}">
        </div>
      </div>
    </form>

    {{-- DELETE FORM (separate, empty body) --}}
    <form id="delete-sub" method="POST"
          action="{{ route('admin.user-subscriptions.destroy', $sub) }}">
      @csrf
      @method('DELETE')
    </form>

    {{-- BUTTONS BAR (targets forms via "form" attribute) --}}
    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary" form="update-sub">Save</button>

      <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-light">Cancel</a>

      <button type="submit"
              class="btn btn-outline-danger ms-auto"
              form="delete-sub"
              formnovalidate
              onclick="return confirm('Delete subscription?')">
        Delete
      </button>
    </div>

  </div>
</div>
@endsection
