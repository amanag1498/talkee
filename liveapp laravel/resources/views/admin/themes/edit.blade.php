@extends('layouts.admin-berry')

@section('title', ($isCreate ?? false) ? 'Create Theme' : 'Edit Theme')

@section('content')
  @php
    $remoteTokensValue = old('remote_tokens_json', $theme->remote_tokens ? json_encode($theme->remote_tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '');
    $previewTokens = null;
    if (is_string($remoteTokensValue) && trim($remoteTokensValue) !== '') {
        $decodedPreview = json_decode($remoteTokensValue, true);
        if (is_array($decodedPreview)) {
            $previewTokens = $decodedPreview;
        }
    } elseif (is_array($theme->preview_tokens)) {
        $previewTokens = $theme->preview_tokens;
    }
  @endphp
  <div class="admin-page-shell">
    <section class="admin-page-hero">
      <span class="admin-page-eyebrow"><i class="ti ti-adjustments"></i> Theme Config</span>
      <h1 class="admin-page-title">{{ ($isCreate ?? false) ? 'Create Theme' : $theme->name }}</h1>
      <p class="admin-page-subtitle">
        @if($isCreate ?? false)
          Add a local, remote, or hybrid theme that can be unlocked without shipping a new app build.
        @else
          Adjust unlock rules, availability windows, and configuration metadata for <code>{{ $theme->key }}</code>.
        @endif
      </p>
    </section>

    <form method="post" action="{{ ($isCreate ?? false) ? route('admin.themes.store') : route('admin.themes.update', $theme) }}" class="card">
      @csrf
      @unless($isCreate ?? false)
        @method('PUT')
      @endunless

      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Theme Key</label>
            <input name="key" class="form-control @error('key') is-invalid @enderror" value="{{ old('key', $theme->key) }}" @disabled(!($isCreate ?? false))>
            @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $theme->name) }}">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $theme->description) }}</textarea>
            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Unlock Type</label>
            <select name="unlock_type" class="form-select @error('unlock_type') is-invalid @enderror">
              @foreach(['free','subscription','vip_subscription','vip_high_tier','first_recharge','recharge_milestone','gift_spend','user_level','host_level','login_streak','pk_event','referral','host_follower_milestone','agency_host_elite','admin_grant','event_reward','festival_event','limited_paid','loyalty','top_spender'] as $unlockType)
                <option value="{{ $unlockType }}" @selected(old('unlock_type', $theme->unlock_type) === $unlockType)>{{ $unlockType }}</option>
              @endforeach
            </select>
            @error('unlock_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Token Source</label>
            <select name="token_source" class="form-select @error('token_source') is-invalid @enderror">
              @foreach(['local', 'remote', 'hybrid'] as $tokenSource)
                <option value="{{ $tokenSource }}" @selected(old('token_source', $theme->token_source ?? 'local') === $tokenSource)>{{ strtoupper($tokenSource) }}</option>
              @endforeach
            </select>
            @error('token_source')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Subscription Plan</label>
            <select name="required_subscription_plan_id" class="form-select @error('required_subscription_plan_id') is-invalid @enderror">
              <option value="">Any / Not Required</option>
              @foreach($subscriptionPlans as $plan)
                <option value="{{ $plan->id }}" @selected((string) old('required_subscription_plan_id', $theme->required_subscription_plan_id) === (string) $plan->id)>{{ $plan->name }} · {{ $plan->price_coins }} coins</option>
              @endforeach
            </select>
            @error('required_subscription_plan_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Sort Order</label>
            <input type="number" min="0" name="sort_order" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $theme->sort_order) }}">
            @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Recharge</label>
            <input type="number" min="0" step="0.01" name="required_total_recharge" class="form-control @error('required_total_recharge') is-invalid @enderror" value="{{ old('required_total_recharge', $theme->required_total_recharge) }}">
            @error('required_total_recharge')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Gift Spend</label>
            <input type="number" min="0" name="required_total_gift_spend" class="form-control @error('required_total_gift_spend') is-invalid @enderror" value="{{ old('required_total_gift_spend', $theme->required_total_gift_spend) }}">
            @error('required_total_gift_spend')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required User Level</label>
            <input type="number" min="0" name="required_user_level" class="form-control @error('required_user_level') is-invalid @enderror" value="{{ old('required_user_level', $theme->required_user_level) }}">
            @error('required_user_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Host Level</label>
            <input type="number" min="0" name="required_host_level" class="form-control @error('required_host_level') is-invalid @enderror" value="{{ old('required_host_level', $theme->required_host_level) }}">
            @error('required_host_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Login Streak</label>
            <input type="number" min="0" name="required_login_streak_days" class="form-control @error('required_login_streak_days') is-invalid @enderror" value="{{ old('required_login_streak_days', $theme->required_login_streak_days) }}">
            @error('required_login_streak_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Referrals</label>
            <input type="number" min="0" name="required_referrals" class="form-control @error('required_referrals') is-invalid @enderror" value="{{ old('required_referrals', $theme->required_referrals) }}">
            @error('required_referrals')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Required Host Followers</label>
            <input type="number" min="0" name="required_host_followers" class="form-control @error('required_host_followers') is-invalid @enderror" value="{{ old('required_host_followers', $theme->required_host_followers) }}">
            @error('required_host_followers')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-3">
            <label class="form-label">Price</label>
            <input type="number" min="0" step="0.01" name="price" class="form-control @error('price') is-invalid @enderror" value="{{ old('price', $theme->price) }}">
            @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Event Key</label>
            <input name="event_key" class="form-control @error('event_key') is-invalid @enderror" value="{{ old('event_key', $theme->event_key) }}">
            @error('event_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Min App Version</label>
            <input type="number" min="1" name="min_app_version" class="form-control @error('min_app_version') is-invalid @enderror" value="{{ old('min_app_version', $theme->min_app_version) }}">
            @error('min_app_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Max App Version</label>
            <input type="number" min="1" name="max_app_version" class="form-control @error('max_app_version') is-invalid @enderror" value="{{ old('max_app_version', $theme->max_app_version) }}">
            @error('max_app_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Starts At</label>
            <input type="datetime-local" name="starts_at" class="form-control @error('starts_at') is-invalid @enderror" value="{{ old('starts_at', optional($theme->starts_at)->format('Y-m-d\TH:i')) }}">
            @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-md-4">
            <label class="form-label">Ends At</label>
            <input type="datetime-local" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at', optional($theme->ends_at)->format('Y-m-d\TH:i')) }}">
            @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-12">
            <label class="form-label">Remote Tokens JSON</label>
            <textarea name="remote_tokens_json" rows="12" class="form-control @error('remote_tokens_json') is-invalid @enderror" spellcheck="false">{{ $remoteTokensValue }}</textarea>
            <div class="form-text">Required keys: {{ implode(', ', $requiredTokenKeys) }}.</div>
            @error('remote_tokens_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-12">
            <label class="form-label">Metadata JSON</label>
            <textarea name="metadata_json" rows="5" class="form-control @error('metadata_json') is-invalid @enderror">{{ old('metadata_json', $theme->metadata ? json_encode($theme->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
            @error('metadata_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
          </div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input type="hidden" name="save_as_draft" value="0">
              <input type="checkbox" class="form-check-input" name="save_as_draft" value="1" @checked(old('save_as_draft', 0))>
              <label class="form-check-label">Save as Draft</label>
            </div>
            @error('save_as_draft')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
          </div>
          @if($previewTokens)
            <div class="col-12">
              <label class="form-label">Preview</label>
              <div class="p-4 rounded border" style="background: linear-gradient(135deg, {{ $previewTokens['backgroundGradient'][0] ?? '#111111' }}, {{ $previewTokens['backgroundGradient'][1] ?? '#222222' }}); color: {{ $previewTokens['textPrimary'] ?? '#FFFFFF' }};">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                  <div class="px-3 py-2 rounded" style="background: linear-gradient(135deg, {{ $previewTokens['cardGradient'][0] ?? '#1B1230' }}, {{ $previewTokens['cardGradient'][1] ?? '#2A1C4A' }}); border: 1px solid {{ $previewTokens['borderColor'] ?? '#FFFFFF' }};">
                    Card
                  </div>
                  <div class="px-3 py-2 rounded-pill" style="background: {{ $previewTokens['chipColor'] ?? '#333333' }}; color: {{ $previewTokens['textPrimary'] ?? '#FFFFFF' }};">
                    Chip
                  </div>
                  <div class="px-3 py-2 rounded" style="background: linear-gradient(135deg, {{ $previewTokens['primaryButtonGradient'][0] ?? '#673AB6' }}, {{ $previewTokens['primaryButtonGradient'][1] ?? '#8B5CF6' }}); color: {{ $previewTokens['textPrimary'] ?? '#FFFFFF' }};">
                    Primary Action
                  </div>
                </div>
                <div class="mt-3 small" style="color: {{ $previewTokens['textSecondary'] ?? '#DDDDDD' }};">
                  Remote preview is rendered from the current token JSON.
                </div>
              </div>
            </div>
          @endif
          <div class="col-md-4">
            <div class="form-check form-switch">
              <input type="hidden" name="is_active" value="0">
              <input type="checkbox" class="form-check-input" name="is_active" value="1" @checked(old('is_active', $theme->is_active))>
              <label class="form-check-label">Active</label>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-check form-switch">
              <input type="hidden" name="is_default" value="0">
              <input type="checkbox" class="form-check-input" name="is_default" value="1" @checked(old('is_default', $theme->is_default))>
              <label class="form-check-label">Default Theme</label>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-check form-switch">
              <input type="hidden" name="is_limited" value="0">
              <input type="checkbox" class="form-check-input" name="is_limited" value="1" @checked(old('is_limited', $theme->is_limited))>
              <label class="form-check-label">Limited Time</label>
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('admin.themes.index') }}" class="btn btn-light border">Back</a>
        <button class="btn btn-primary">{{ ($isCreate ?? false) ? 'Create Theme' : 'Save Theme' }}</button>
      </div>
    </form>
  </div>
@endsection
