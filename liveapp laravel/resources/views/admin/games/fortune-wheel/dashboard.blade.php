@extends('layouts.admin-berry')

@section('title', 'Fortune Wheel')

@section('content')
@php
  $segments = collect($payload['segments'] ?? []);
  $recentSpins = collect($payload['recent_spins'] ?? []);
  $summary = $payload['summary'] ?? [];
  $settings = $payload['settings'] ?? [];
  $expected = $payload['expected_value'] ?? [];
  $entryPacks = collect($payload['entry_packs'] ?? []);
  $subscriptionPlans = collect($payload['subscription_plans'] ?? []);
  $eligibleSegmentIds = collect($payload['eligible_segment_ids'] ?? [])->map(fn ($id) => (int) $id);
  $healthWarnings = collect($payload['health_warnings'] ?? []);
  $eligibleWeight = max(0, (int) ($expected['total_weight'] ?? 0));
  $coinsCollected = (int) ($summary['coins_collected_today'] ?? 0);
  $coinsRewarded = (int) ($summary['coins_rewarded_today'] ?? 0);
  $netCoinFlow = $coinsCollected - $coinsRewarded;
@endphp

<div class="admin-page-shell">
  <section class="admin-page-hero mb-4">
    <div class="row g-3 align-items-center">
      <div class="col-lg-8">
        <span class="admin-page-eyebrow"><i class="ti ti-wheel"></i> Game Operations</span>
        <h1 class="admin-page-title">Fortune Wheel Control Room</h1>
        <p class="admin-page-subtitle">Manage reward segments, selection odds, daily free spins, paid-spin economy, and recent outcomes.</p>
      </div>
      <div class="col-lg-4">
        <div class="admin-page-actions">
          <a class="btn btn-light border" href="{{ route('admin.entry-packs.index') }}">Entry Packs</a>
          <a class="btn btn-light border" href="{{ route('admin.subscription-plans.index') }}">Subscriptions</a>
          <a class="btn btn-primary" href="{{ route('admin.settings.games.edit', ['game' => 'fortune_wheel']) }}">Game Settings</a>
        </div>
      </div>
    </div>
  </section>

  @if($healthWarnings->isNotEmpty())
    <div class="alert alert-warning">
      <div class="fw-semibold mb-2">Action needed before players spin</div>
      <ul class="mb-0 ps-3">
        @foreach($healthWarnings as $warning)<li>{{ $warning }}</li>@endforeach
      </ul>
    </div>
  @endif

  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif

  <div class="row g-3 mb-4">
    @foreach([
      ['Status', !empty($settings['enabled']) ? 'Enabled' : 'Disabled', number_format((int) ($summary['eligible_segments'] ?? 0)).' selectable of '.number_format((int) ($summary['configured_segments'] ?? 0)).' configured'],
      ['Spins Today', number_format((int) ($summary['spins_today'] ?? 0)), number_format((int) ($summary['free_spins_today'] ?? 0)).' free, '.number_format((int) ($summary['paid_spins_today'] ?? 0)).' paid'],
      ['Net Coin Flow', ($netCoinFlow >= 0 ? '+' : '').number_format($netCoinFlow), number_format($coinsCollected).' collected, '.number_format($coinsRewarded).' rewarded'],
      ['Paid Spin Margin', number_format((float) ($expected['estimated_coin_margin'] ?? 0), 2), number_format((float) ($expected['estimated_coin_margin_percent'] ?? 0), 1).'% before entitlement value'],
    ] as $stat)
      <div class="col-lg-3 col-md-6"><div class="card h-100"><div class="card-body">
        <div class="text-muted small">{{ $stat[0] }}</div><div class="fs-4 fw-semibold">{{ $stat[1] }}</div><div class="small text-muted mt-1">{{ $stat[2] }}</div>
      </div></div></div>
    @endforeach
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">Reward Mix</h5></div><div class="card-body">
      <div class="row g-3">
        @foreach([['Zero coins', 'zero_coin_probability'], ['Entry pack', 'entry_pack_probability'], ['Subscription', 'subscription_probability']] as $metric)
          <div class="col-sm-4"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">{{ $metric[0] }}</div><div class="fs-5 fw-semibold">{{ number_format((float) ($expected[$metric[1]] ?? 0), 2) }}%</div></div></div>
        @endforeach
      </div>
      <div class="form-text mt-3">Average coin reward: {{ number_format((float) ($expected['average_coin_reward'] ?? 0), 2) }}. Timed reward value is intentionally excluded from coin margin.</div>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">Catalog Readiness</h5></div><div class="card-body">
      <div class="row g-3">
        <div class="col-6"><a class="border rounded-3 p-3 d-block text-decoration-none" href="{{ route('admin.entry-packs.index') }}"><div class="text-muted small">Active entry packs</div><div class="fs-4 fw-semibold">{{ number_format($entryPacks->count()) }}</div></a></div>
        <div class="col-6"><a class="border rounded-3 p-3 d-block text-decoration-none" href="{{ route('admin.subscription-plans.index') }}"><div class="text-muted small">Active plans</div><div class="fs-4 fw-semibold">{{ number_format($subscriptionPlans->count()) }}</div></a></div>
      </div>
    </div></div></div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h5 class="mb-1">Create Segment</h5><div class="text-muted small">Each active segment is a real result. A 0 Coin segment is valid.</div></div>
    <div class="card-body">
      <form method="post" action="{{ route('admin.games.fortune-wheel.segments.store') }}" class="row g-3" data-fortune-segment-form>
        @csrf
        @include('admin.games.fortune-wheel.segment-form', ['segment' => null])
        <div class="col-12 text-end"><button class="btn btn-primary">Add Segment</button></div>
      </form>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h5 class="mb-1">Wheel Segments</h5><div class="text-muted small">Runtime eligibility and chance use active, valid catalog rewards only.</div></div>
    <div class="card-body">
      <div class="row g-3">
        @forelse($segments as $segment)
          @php
            $isEligible = $eligibleSegmentIds->contains((int) $segment->id);
            $probability = $isEligible && $eligibleWeight > 0 ? ((int) $segment->weight / $eligibleWeight) * 100 : 0;
          @endphp
          <div class="col-xl-6"><div class="border rounded-3 h-100">
            <div class="p-3 border-bottom d-flex justify-content-between gap-3">
              <div class="d-flex gap-2"><span class="rounded-2" style="width:38px;height:38px;background:{{ $segment->color ?: '#7C3AED' }}"></span><div><div class="fw-semibold">{{ $segment->label }}</div><div class="text-muted small">#{{ $segment->id }} · {{ ucfirst(str_replace('_', ' ', $segment->reward_type)) }}</div></div></div>
              <div class="text-end"><span class="badge {{ $segment->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $segment->is_active ? 'Active' : 'Inactive' }}</span><div class="small mt-1 {{ $isEligible ? 'text-primary' : 'text-warning' }}">{{ $isEligible ? number_format($probability, 2).'% chance' : 'Not selectable' }}</div></div>
            </div>
            @if($segment->is_active && !$isEligible)<div class="alert alert-warning rounded-0 mb-0 py-2 small">Excluded because the linked catalog reward is missing or inactive.</div>@endif
            <form id="segment-{{ $segment->id }}" method="post" action="{{ route('admin.games.fortune-wheel.segments.update', $segment) }}" class="row g-3 p-3" data-fortune-segment-form>
              @csrf @method('PUT')
              @include('admin.games.fortune-wheel.segment-form', ['segment' => $segment])
            </form>
            <div class="p-3 border-top d-flex justify-content-between align-items-center"><div class="text-muted small">Weight {{ number_format((int) $segment->weight) }} · Order {{ number_format((int) $segment->sort_order) }}</div><div class="d-flex gap-2">
              <form method="post" action="{{ route('admin.games.fortune-wheel.segments.destroy', $segment) }}" onsubmit="return confirm('Delete this wheel segment?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
              <button type="submit" form="segment-{{ $segment->id }}" class="btn btn-sm btn-primary">Save</button>
            </div></div>
          </div></div>
        @empty
          <div class="col-12 text-center text-muted py-5">No Fortune Wheel segments configured yet.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="mb-1">Recent Spins</h5><div class="text-muted small">Latest gameplay records for free-spin limits, coin debits, and reward auditing.</div></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>User</th><th>Type</th><th>Cost</th><th>Reward</th><th>Spin Date</th><th>Created</th></tr></thead><tbody>
      @forelse($recentSpins as $spin)
        <tr><td><div class="fw-semibold">{{ $spin->user?->name ?? 'User #'.$spin->user_id }}</div><div class="small text-muted">{{ $spin->user?->email }}</div></td><td><span class="badge {{ $spin->spin_type === 'free' ? 'bg-success' : 'bg-primary' }}">{{ ucfirst($spin->spin_type) }}</span></td><td>{{ number_format((int) $spin->spin_cost_coins) }} coins</td><td><div class="fw-semibold">{{ data_get($spin->meta, 'segment_label') ?? $spin->segment?->label ?? ucfirst(str_replace('_', ' ', $spin->reward_type)) }}</div><div class="small text-muted">{{ ucfirst(str_replace('_', ' ', $spin->reward_type)) }}</div></td><td>{{ optional($spin->spun_for_date)->toDateString() }}</td><td>{{ optional($spin->created_at)->format('d M Y, H:i:s') }}</td></tr>
      @empty<tr><td colspan="6" class="text-center text-muted py-4">No spins yet.</td></tr>@endforelse
    </tbody></table></div>
  </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-fortune-segment-form]').forEach((form) => {
    const rewardType = form.querySelector('[data-fortune-reward-type]');
    const colorPicker = form.querySelector('[data-fortune-color-picker]');
    const colorText = form.querySelector('[data-fortune-color-text]');
    const sync = () => {
      const type = rewardType?.value ?? 'coins';
      form.querySelectorAll('[data-fortune-field]').forEach((wrapper) => {
        const field = wrapper.dataset.fortuneField;
        const visible = field === type || (field === 'duration' && ['entry_pack', 'subscription'].includes(type));
        wrapper.classList.toggle('d-none', !visible);
        wrapper.querySelectorAll('input, select').forEach((input) => input.disabled = !visible);
      });
    };
    rewardType?.addEventListener('change', sync);
    colorPicker?.addEventListener('input', () => colorText.value = colorPicker.value.toUpperCase());
    colorText?.addEventListener('input', () => { if (/^#[0-9A-Fa-f]{6}$/.test(colorText.value)) colorPicker.value = colorText.value; });
    sync();
  });
});
</script>
@endpush
