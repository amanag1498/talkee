@php
  $formContext = $segment ? 'segment-'.$segment->id : 'new';
  $useOldInput = old('_segment_context') === $formContext;
  $fieldValue = static fn (string $field, mixed $default = null) => $useOldInput ? old($field, $default) : $default;
  $rewardType = $fieldValue('reward_type', $segment?->reward_type ?? 'coins');
  $color = $fieldValue('color', $segment?->color ?? '#7C3AED');
@endphp

<input type="hidden" name="_segment_context" value="{{ $formContext }}">

<div class="col-md-6">
  <label class="form-label">Label</label>
  <input class="form-control" name="label" value="{{ $fieldValue('label', $segment?->label) }}" placeholder="e.g. 100 Coins" required>
</div>
<div class="col-md-6">
  <label class="form-label">Reward Type</label>
  <select class="form-select" name="reward_type" data-fortune-reward-type>
    @foreach(\App\Models\FortuneWheelSegment::REWARD_TYPES as $type)
      <option value="{{ $type }}" @selected($rewardType === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
    @endforeach
  </select>
</div>
<div class="col-md-6" data-fortune-field="coins">
  <label class="form-label">Coin Reward</label>
  <input type="number" min="0" class="form-control" name="reward_value_coins" value="{{ $fieldValue('reward_value_coins', $segment?->reward_value_coins ?? 0) }}">
  <div class="form-text">Zero is a valid reward and never becomes Try Again.</div>
</div>
<div class="col-md-6" data-fortune-field="entry_pack">
  <label class="form-label">Entry Pack</label>
  <select class="form-select" name="entry_pack_id">
    <option value="">Choose an active pack</option>
    @foreach($entryPacks as $pack)
      <option value="{{ $pack->id }}" @selected((int) $fieldValue('entry_pack_id', $segment?->entry_pack_id) === (int) $pack->id)>{{ $pack->name }}</option>
    @endforeach
  </select>
</div>
<div class="col-md-6" data-fortune-field="subscription">
  <label class="form-label">Subscription</label>
  <select class="form-select" name="subscription_plan_id">
    <option value="">Choose an active plan</option>
    @foreach($subscriptionPlans as $plan)
      <option value="{{ $plan->id }}" @selected((int) $fieldValue('subscription_plan_id', $segment?->subscription_plan_id) === (int) $plan->id)>{{ $plan->name }}</option>
    @endforeach
  </select>
</div>
<div class="col-md-6" data-fortune-field="duration">
  <label class="form-label">Reward Duration (hours)</label>
  <input type="number" min="1" max="8760" class="form-control" name="reward_duration_hours" value="{{ $fieldValue('reward_duration_hours', $segment?->reward_duration_hours) }}">
</div>
<div class="col-md-4">
  <label class="form-label">Selection Weight</label>
  <input type="number" min="1" class="form-control" name="weight" value="{{ $fieldValue('weight', $segment?->weight ?? 1) }}" required>
</div>
<div class="col-md-4">
  <label class="form-label">Display Order</label>
  <input type="number" min="0" class="form-control" name="sort_order" value="{{ $fieldValue('sort_order', $segment?->sort_order ?? 0) }}" required>
</div>
<div class="col-md-4">
  <label class="form-label">Wheel Color</label>
  <div class="input-group">
    <input type="color" value="{{ $color }}" class="form-control form-control-color" data-fortune-color-picker aria-label="Choose wheel color">
    <input class="form-control font-monospace text-uppercase" name="color" value="{{ $color }}" maxlength="7" data-fortune-color-text>
  </div>
</div>
<div class="col-md-9">
  <label class="form-label">Reward Icon URL</label>
  <input type="url" class="form-control" name="icon_url" value="{{ $fieldValue('icon_url', $segment?->icon_url) }}" placeholder="https://.../reward.png">
</div>
<div class="col-md-3 d-flex align-items-end">
  <div class="form-check form-switch mb-2">
    <input type="hidden" name="is_active" value="0">
    <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked((bool) $fieldValue('is_active', $segment?->is_active ?? true))>
    <label class="form-check-label">Selectable and visible</label>
  </div>
</div>
