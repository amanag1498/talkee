<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="ti ti-wallet me-2"></i>{{ $mode === 'create' ? 'Create Recharge Plan' : 'Edit Recharge Plan' }}</h6>
    <a href="{{ route('admin.recharge-plans.index') }}" class="btn btn-light border">Back</a>
  </div>
  <div class="card-body row g-3">
    <div class="col-md-5">
      <label class="form-label">Title</label>
      <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $plan->title) }}" required>
      @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
      <label class="form-label">Amount (₹)</label>
      <input type="number" step="0.01" min="1" name="amount_rupees" class="form-control @error('amount_rupees') is-invalid @enderror" value="{{ old('amount_rupees', $plan->amount_rupees) }}" required>
      @error('amount_rupees')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
      <label class="form-label">Sort Order</label>
      <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $plan->sort_order) }}" required>
      @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-2">
      <label class="form-label d-block">Active</label>
      <div class="form-check form-switch mt-2">
        <input type="hidden" name="is_active" value="0">
        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $plan->is_active))>
        <label class="form-check-label">Enabled</label>
      </div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Base Coins</label>
      <input type="number" min="1" name="coins" class="form-control @error('coins') is-invalid @enderror" value="{{ old('coins', $plan->coins) }}" required>
      @error('coins')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
      <label class="form-label">Bonus Coins</label>
      <input type="number" min="0" name="bonus_coins" class="form-control @error('bonus_coins') is-invalid @enderror" value="{{ old('bonus_coins', $plan->bonus_coins) }}">
      @error('bonus_coins')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
      <label class="form-label">Agency Extra Bonus</label>
      <input type="number" min="0" name="agency_bonus_coins" class="form-control @error('agency_bonus_coins') is-invalid @enderror" value="{{ old('agency_bonus_coins', $plan->agency_bonus_coins ?? 0) }}">
      @error('agency_bonus_coins')<div class="invalid-feedback">{{ $message }}</div>@enderror
      <small class="text-muted">Added only when an agency recharges a user.</small>
    </div>
    <div class="col-md-3">
      <label class="form-label">App Recharge Total</label>
      <input type="text" class="form-control" value="{{ number_format((int) ($plan->total_coins ?? (($plan->coins ?? 0) + ($plan->bonus_coins ?? 0)))) }}" disabled>
      <small class="text-muted">Normal total stays base + bonus. Agency user total: {{ number_format((int) ($plan->total_coins ?? (($plan->coins ?? 0) + ($plan->bonus_coins ?? 0))) + (int) ($plan->agency_bonus_coins ?? 0)) }}.</small>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end gap-2">
    <a href="{{ route('admin.recharge-plans.index') }}" class="btn btn-light border">Cancel</a>
    <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>{{ $mode === 'create' ? 'Create Plan' : 'Save Changes' }}</button>
  </div>
</div>
