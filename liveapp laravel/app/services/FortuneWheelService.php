<?php

namespace App\Services;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\FortuneWheelSpin;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserEntryPack;
use App\Models\UserSubscription;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FortuneWheelService
{
    public function enabled(): bool
    {
        return (bool) config('games.fortune_wheel.enabled', false);
    }

    public function visibleInVideoRoomStrip(): bool
    {
        return (bool) config('games.fortune_wheel.visible_in_video_room_strip', true);
    }

    public function freeSpinsPerDay(): int
    {
        return max(0, (int) config('games.fortune_wheel.free_spins_per_day', 1));
    }

    public function paidSpinCostCoins(): int
    {
        return max(0, (int) config('games.fortune_wheel.paid_spin_cost_coins', 50));
    }

    public function paidSpinsEnabled(): bool
    {
        return (bool) config('games.fortune_wheel.paid_spins_enabled', true);
    }

    public function timezone(): string
    {
        $timezone = trim((string) config('games.fortune_wheel.timezone', 'Asia/Kolkata'));

        return $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }

    public function publicSettings(): array
    {
        return [
            'enabled' => $this->enabled(),
            'visible_in_video_room_strip' => $this->visibleInVideoRoomStrip(),
            'free_spins_per_day' => $this->freeSpinsPerDay(),
            'paid_spin_cost_coins' => $this->paidSpinCostCoins(),
            'paid_spins_enabled' => $this->paidSpinsEnabled(),
            'timezone' => $this->timezone(),
        ];
    }

    public function snapshot(User $user): array
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Fortune Wheel is currently unavailable.');
        }

        $wallet = WalletService::getOrCreate($user);
        $spunForDate = $this->spunForDate();
        $segments = $this->activeSegments()->get();

        return [
            'settings' => $this->publicSettings(),
            'wallet_balance' => (int) $wallet->balance,
            'spun_for_date' => $spunForDate,
            'free_spins_remaining' => $this->freeSpinsRemaining($user, $spunForDate),
            'segments' => $segments->map(fn (FortuneWheelSegment $segment) => $this->segmentPayload($segment))->values()->all(),
            'recent_spins' => FortuneWheelSpin::query()
                ->with(['segment', 'entryPack', 'subscriptionPlan'])
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (FortuneWheelSpin $spin) => $this->spinPayload($spin))
                ->values()
                ->all(),
        ];
    }

    public function spin(User $user, ?string $idempotencyKey = null): array
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Fortune Wheel is currently unavailable.');
        }

        $normalizedKey = $idempotencyKey ? Str::limit(trim($idempotencyKey), 150, '') : null;

        $spin = DB::transaction(function () use ($user, $normalizedKey) {
            if ($normalizedKey) {
                $existing = FortuneWheelSpin::query()
                    ->with(['segment', 'entryPack', 'subscriptionPlan'])
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $normalizedKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $segments = $this->activeSegments()->lockForUpdate()->get();
            if ($segments->isEmpty()) {
                throw new HttpException(409, 'Fortune Wheel has no active rewards configured.');
            }

            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            WalletService::getOrCreate($user);

            $spunForDate = $this->spunForDate();
            $freeUsed = FortuneWheelSpin::query()
                ->where('user_id', $user->id)
                ->whereDate('spun_for_date', $spunForDate)
                ->where('spin_type', FortuneWheelSpin::TYPE_FREE)
                ->lockForUpdate()
                ->count();
            $spinType = $freeUsed < $this->freeSpinsPerDay()
                ? FortuneWheelSpin::TYPE_FREE
                : FortuneWheelSpin::TYPE_PAID;
            $spinCost = $spinType === FortuneWheelSpin::TYPE_PAID ? $this->paidSpinCostCoins() : 0;

            if ($spinType === FortuneWheelSpin::TYPE_PAID && ! $this->paidSpinsEnabled()) {
                throw new HttpException(422, 'Paid Fortune Wheel spins are disabled.');
            }

            $walletDebit = null;
            if ($spinCost > 0) {
                try {
                    $walletDebit = WalletService::spend(
                        user: $user,
                        coins: $spinCost,
                        category: 'other',
                        counterparty: null,
                        reference: 'fortune_wheel_spin:'.($normalizedKey ?: Str::uuid()->toString()),
                        meta: [
                            'game' => 'fortune_wheel',
                            'event' => 'FORTUNE_WHEEL_SPIN_DEBIT',
                            'spin_type' => $spinType,
                            'idempotency_key' => $normalizedKey,
                        ],
                    );
                } catch (\InvalidArgumentException) {
                    throw new HttpException(422, 'Insufficient wallet balance.');
                }
            }

            $segment = $this->weightedSegment($segments);
            $spin = FortuneWheelSpin::query()->create([
                'user_id' => $user->id,
                'fortune_wheel_segment_id' => $segment->id,
                'spin_type' => $spinType,
                'spin_cost_coins' => $spinCost,
                'reward_type' => $segment->reward_type,
                'reward_value_coins' => (int) $segment->reward_value_coins,
                'entry_pack_id' => $segment->entry_pack_id,
                'subscription_plan_id' => $segment->subscription_plan_id,
                'reward_duration_hours' => $segment->reward_duration_hours,
                'wallet_debit_transaction_id' => $walletDebit?->id,
                'idempotency_key' => $normalizedKey,
                'spun_for_date' => $spunForDate,
                'meta' => [
                    'segment_label' => $segment->label,
                    'segment_weight' => (int) $segment->weight,
                ],
            ]);

            $rewardRefs = $this->applyReward($user, $segment, $spin);
            if ($rewardRefs !== []) {
                $spin->forceFill($rewardRefs)->save();
            }

            return $spin->fresh(['segment', 'entryPack', 'subscriptionPlan', 'userEntryPack.entryPack', 'userSubscription.plan']);
        });

        return [
            'spin' => $this->spinPayload($spin),
            'free_spins_remaining' => $this->freeSpinsRemaining($user, $this->spunForDate()),
            'wallet_balance' => (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
            'segments' => $this->activeSegments()
                ->get()
                ->map(fn (FortuneWheelSegment $segment) => $this->segmentPayload($segment))
                ->values()
                ->all(),
        ];
    }

    public function history(User $user, int $limit = 20): array
    {
        return FortuneWheelSpin::query()
            ->with(['segment', 'entryPack', 'subscriptionPlan'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (FortuneWheelSpin $spin) => $this->spinPayload($spin))
            ->values()
            ->all();
    }

    public function adminDashboardPayload(): array
    {
        $today = $this->spunForDate();
        $todaySpins = FortuneWheelSpin::query()->whereDate('spun_for_date', $today);
        $paidSpins = (clone $todaySpins)->where('spin_type', FortuneWheelSpin::TYPE_PAID);
        $segments = FortuneWheelSegment::query()
            ->with(['entryPack', 'subscriptionPlan'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $eligibleSegments = $this->activeSegments()->get();
        $eligibleSegmentIds = $eligibleSegments->pluck('id')->map(fn ($id) => (int) $id)->values();
        $entryPacks = EntryPack::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $subscriptionPlans = SubscriptionPlan::query()->where('is_active', true)->orderBy('price_coins')->orderBy('id')->get();
        $expectedValue = $this->expectedValuePayload($eligibleSegments);
        $activeConfigured = $segments->where('is_active', true);
        $ineligibleActive = $activeConfigured->reject(
            fn (FortuneWheelSegment $segment) => $eligibleSegmentIds->contains((int) $segment->id),
        );
        $healthWarnings = [];

        if ($this->enabled() && $eligibleSegments->isEmpty()) {
            $healthWarnings[] = 'The game is enabled but has no selectable reward segments.';
        }
        if ($ineligibleActive->isNotEmpty()) {
            $healthWarnings[] = $ineligibleActive->count().' active segment(s) are excluded because their linked catalog reward is missing or inactive.';
        }
        if ($entryPacks->isEmpty()) {
            $healthWarnings[] = 'No active entry packs are available for Fortune Wheel rewards.';
        }
        if ($subscriptionPlans->isEmpty()) {
            $healthWarnings[] = 'No active subscription plans are available for Fortune Wheel rewards.';
        }
        if ($this->paidSpinsEnabled() && (float) $expectedValue['estimated_coin_margin'] <= 0) {
            $healthWarnings[] = 'Paid spin coin margin is zero or negative before valuing entry-pack and subscription rewards.';
        }

        return [
            'settings' => $this->publicSettings(),
            'segments' => $segments,
            'recent_spins' => FortuneWheelSpin::query()
                ->with(['user', 'segment', 'entryPack', 'subscriptionPlan'])
                ->latest('id')
                ->limit(25)
                ->get(),
            'summary' => [
                'today' => $today,
                'spins_today' => (int) (clone $todaySpins)->count(),
                'free_spins_today' => (int) (clone $todaySpins)->where('spin_type', FortuneWheelSpin::TYPE_FREE)->count(),
                'paid_spins_today' => (int) (clone $paidSpins)->count(),
                'coins_collected_today' => (int) (clone $paidSpins)->sum('spin_cost_coins'),
                'coins_rewarded_today' => (int) (clone $todaySpins)->where('reward_type', FortuneWheelSegment::REWARD_COINS)->sum('reward_value_coins'),
                'configured_segments' => $segments->count(),
                'active_segments' => $activeConfigured->count(),
                'eligible_segments' => $eligibleSegments->count(),
                'ineligible_active_segments' => $ineligibleActive->count(),
            ],
            'expected_value' => $expectedValue,
            'eligible_segment_ids' => $eligibleSegmentIds->all(),
            'health_warnings' => $healthWarnings,
            'entry_packs' => $entryPacks,
            'subscription_plans' => $subscriptionPlans,
        ];
    }

    public function segmentsQuery(): Builder
    {
        return FortuneWheelSegment::query()
            ->with(['entryPack', 'subscriptionPlan'])
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function activeSegments(): Builder
    {
        return FortuneWheelSegment::query()
            ->with(['entryPack', 'subscriptionPlan'])
            ->where('is_active', true)
            ->where('weight', '>', 0)
            ->where(function ($query) {
                $query
                    ->where('reward_type', FortuneWheelSegment::REWARD_COINS)
                    ->orWhere(function ($entryPackQuery) {
                        $entryPackQuery
                            ->where('reward_type', FortuneWheelSegment::REWARD_ENTRY_PACK)
                            ->whereNotNull('entry_pack_id')
                            ->whereNotNull('reward_duration_hours')
                            ->whereHas('entryPack', fn ($entryPack) => $entryPack->where('is_active', true));
                    })
                    ->orWhere(function ($subscriptionQuery) {
                        $subscriptionQuery
                            ->where('reward_type', FortuneWheelSegment::REWARD_SUBSCRIPTION)
                            ->whereNotNull('subscription_plan_id')
                            ->whereNotNull('reward_duration_hours')
                            ->whereHas('subscriptionPlan', fn ($plan) => $plan->where('is_active', true));
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function weightedSegment(Collection $segments): FortuneWheelSegment
    {
        $totalWeight = (int) $segments->sum(fn (FortuneWheelSegment $segment) => max(0, (int) $segment->weight));
        if ($totalWeight <= 0) {
            throw new HttpException(409, 'Fortune Wheel reward weights are not configured.');
        }

        $roll = random_int(1, $totalWeight);
        $cursor = 0;
        foreach ($segments as $segment) {
            $cursor += max(0, (int) $segment->weight);
            if ($roll <= $cursor) {
                return $segment;
            }
        }

        return $segments->last();
    }

    private function applyReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        return match ($segment->reward_type) {
            FortuneWheelSegment::REWARD_COINS => $this->applyCoinReward($user, $segment, $spin),
            FortuneWheelSegment::REWARD_ENTRY_PACK => $this->applyEntryPackReward($user, $segment, $spin),
            FortuneWheelSegment::REWARD_SUBSCRIPTION => $this->applySubscriptionReward($user, $segment, $spin),
            default => [],
        };
    }

    private function applyCoinReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        $rewardCoins = (int) $segment->reward_value_coins;
        if ($rewardCoins <= 0) {
            return [];
        }

        $walletCredit = WalletService::credit(
            user: $user,
            coins: $rewardCoins,
            reference: 'fortune_wheel_spin:'.$spin->id,
            meta: [
                'game' => 'fortune_wheel',
                'event' => 'FORTUNE_WHEEL_REWARD_CREDIT',
                'spin_id' => $spin->id,
                'segment_id' => $segment->id,
            ],
            attributes: [
                'category' => 'game_reward_credit',
                'reference_type' => 'fortune_wheel_spin',
                'reference_id' => $spin->id,
            ],
            description: 'Fortune Wheel coin reward',
        );

        return ['wallet_credit_transaction_id' => $walletCredit->id];
    }

    private function applyEntryPackReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        $durationHours = max(1, (int) $segment->reward_duration_hours);
        $alreadyActive = UserEntryPack::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        $userPack = UserEntryPack::query()->create([
            'user_id' => $user->id,
            'entry_pack_id' => (int) $segment->entry_pack_id,
            'is_active' => ! $alreadyActive,
            'purchased_at' => now(),
            'expires_at' => now()->addHours($durationHours),
            'purchase_key' => 'fortune_wheel:'.$spin->id,
            'source' => 'fortune_wheel',
            'charged' => false,
            'price_coins' => 0,
            'purchase_reference' => 'fortune_wheel_spin:'.$spin->id,
        ]);

        return ['user_entry_pack_id' => $userPack->id];
    }

    private function applySubscriptionReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        $durationHours = max(1, (int) $segment->reward_duration_hours);
        $now = now();
        $active = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('subscription_plan_id', $segment->subscription_plan_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        $base = ($active && $active->ends_at && $active->ends_at->gt($now))
            ? $active->ends_at->clone()
            : $now->clone();
        $endsAt = $base->addHours($durationHours);
        $meta = [
            'source' => 'fortune_wheel',
            'charged' => false,
            'spin_id' => $spin->id,
            'segment_id' => $segment->id,
            'reward_duration_hours' => $durationHours,
            'granted_at' => $now->toIso8601String(),
        ];

        if ($active) {
            $active->update([
                'ends_at' => $endsAt,
                'last_purchased_at' => $now,
                'status' => 'active',
                'meta' => array_merge($active->meta ?? [], $meta),
            ]);

            return ['user_subscription_id' => $active->id];
        }

        $subscription = UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => (int) $segment->subscription_plan_id,
            'status' => 'active',
            'starts_at' => $now,
            'ends_at' => $endsAt,
            'last_purchased_at' => $now,
            'meta' => $meta,
        ]);

        return ['user_subscription_id' => $subscription->id];
    }

    private function freeSpinsRemaining(User $user, string $spunForDate): int
    {
        $used = (int) FortuneWheelSpin::query()
            ->where('user_id', $user->id)
            ->whereDate('spun_for_date', $spunForDate)
            ->where('spin_type', FortuneWheelSpin::TYPE_FREE)
            ->count();

        return max(0, $this->freeSpinsPerDay() - $used);
    }

    private function spunForDate(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    private function expectedValuePayload(?Collection $segments = null): array
    {
        $segments ??= $this->activeSegments()->get();
        $totalWeight = (int) $segments->sum('weight');
        $coinExpected = $totalWeight > 0
            ? (float) $segments
                ->where('reward_type', FortuneWheelSegment::REWARD_COINS)
                ->sum(fn (FortuneWheelSegment $segment) => (int) $segment->reward_value_coins * (int) $segment->weight) / $totalWeight
            : 0.0;

        $paidSpinCost = $this->paidSpinCostCoins();
        $estimatedMargin = $paidSpinCost - $coinExpected;
        $weightFor = fn (string $rewardType): int => (int) $segments
            ->where('reward_type', $rewardType)
            ->sum('weight');
        $probabilityForWeight = fn (int $weight): float => $totalWeight > 0
            ? round(($weight / $totalWeight) * 100, 2)
            : 0.0;

        return [
            'total_weight' => $totalWeight,
            'average_coin_reward' => round($coinExpected, 2),
            'paid_spin_cost_coins' => $paidSpinCost,
            'estimated_coin_margin' => round($estimatedMargin, 2),
            'estimated_coin_margin_percent' => $paidSpinCost > 0
                ? round(($estimatedMargin / $paidSpinCost) * 100, 2)
                : 0.0,
            'zero_coin_probability' => $probabilityForWeight((int) $segments
                ->where('reward_type', FortuneWheelSegment::REWARD_COINS)
                ->where('reward_value_coins', 0)
                ->sum('weight')),
            'entry_pack_probability' => $probabilityForWeight($weightFor(FortuneWheelSegment::REWARD_ENTRY_PACK)),
            'subscription_probability' => $probabilityForWeight($weightFor(FortuneWheelSegment::REWARD_SUBSCRIPTION)),
        ];
    }

    private function segmentPayload(FortuneWheelSegment $segment): array
    {
        return [
            'id' => $segment->id,
            'label' => $segment->label,
            'reward_type' => $segment->reward_type,
            'reward_value_coins' => (int) $segment->reward_value_coins,
            'entry_pack_id' => $segment->entry_pack_id,
            'entry_pack_name' => $segment->entryPack?->name,
            'subscription_plan_id' => $segment->subscription_plan_id,
            'subscription_plan_name' => $segment->subscriptionPlan?->name,
            'reward_duration_hours' => $segment->reward_duration_hours,
            'weight' => (int) $segment->weight,
            'color' => $segment->color,
            'icon_url' => $segment->icon_url,
            'sort_order' => (int) $segment->sort_order,
        ];
    }

    private function spinPayload(FortuneWheelSpin $spin): array
    {
        return [
            'id' => $spin->id,
            'spin_type' => $spin->spin_type,
            'spin_cost_coins' => (int) $spin->spin_cost_coins,
            'reward_type' => $spin->reward_type,
            'reward_value_coins' => (int) $spin->reward_value_coins,
            'entry_pack_id' => $spin->entry_pack_id,
            'entry_pack_name' => $spin->entryPack?->name,
            'subscription_plan_id' => $spin->subscription_plan_id,
            'subscription_plan_name' => $spin->subscriptionPlan?->name,
            'reward_duration_hours' => $spin->reward_duration_hours,
            'segment' => $spin->segment ? $this->segmentPayload($spin->segment) : null,
            'spun_for_date' => optional($spin->spun_for_date)->toDateString(),
            'created_at' => optional($spin->created_at)->toIso8601String(),
        ];
    }
}
