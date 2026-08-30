<?php

namespace App\Services;

use App\Models\EntryPack;
use App\Models\FortuneWheelSegment;
use App\Models\FortuneWheelSpin;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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

            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $segments = $this->activeSegments()->lockForUpdate()->get();
            if ($segments->isEmpty()) {
                throw new HttpException(409, 'Fortune Wheel has no active rewards configured.');
            }

            WalletService::getOrCreate($user);
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

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

            if ($spinCost > 0 && (int) $wallet->balance < $spinCost) {
                throw new HttpException(422, 'Insufficient wallet balance.');
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
                'idempotency_key' => $normalizedKey,
                'spun_for_date' => $spunForDate,
                'meta' => [
                    'segment_label' => $segment->label,
                    'segment_weight' => (int) $segment->weight,
                ],
            ]);

            $walletDebit = null;
            if ($spinCost > 0) {
                $balanceBefore = (int) $wallet->balance;
                $balanceAfter = $balanceBefore - $spinCost;
                $wallet->update(['balance' => $balanceAfter]);

                $walletDebit = WalletTransaction::query()->create([
                    'wallet_id' => $wallet->id,
                    'type' => 'debit',
                    'coins' => $spinCost,
                    'category' => 'game_bet_debit',
                    'reference' => 'fortune_wheel_spin:'.$spin->id,
                    'reference_type' => 'fortune_wheel_spin',
                    'reference_id' => $spin->id,
                    'description' => 'Fortune Wheel paid spin',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'meta' => [
                        'game' => 'fortune_wheel',
                        'event' => 'FORTUNE_WHEEL_SPIN_DEBIT',
                        'spin_id' => $spin->id,
                        'segment_id' => $segment->id,
                        'spin_type' => $spinType,
                        'idempotency_key' => $normalizedKey,
                    ],
                ]);
            }

            $rewardRefs = $this->applyReward($user, $segment, $spin);
            if ($walletDebit) {
                $rewardRefs['wallet_debit_transaction_id'] = $walletDebit->id;
            }
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

    public function adminDashboardPayload(array $filters = []): array
    {
        $today = $this->spunForDate();
        $todaySpins = FortuneWheelSpin::query()->whereDate('spun_for_date', $today);
        $weekStart = CarbonImmutable::parse($today, $this->timezone())->startOfWeek()->toDateString();
        $weekSpins = FortuneWheelSpin::query()
            ->whereDate('spun_for_date', '>=', $weekStart)
            ->whereDate('spun_for_date', '<=', $today);
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

        $auditQuery = $this->filteredAdminSpinsQuery($filters);
        $auditSummary = $this->aggregateSpinQuery(clone $auditQuery);
        $dailyBreakdown = (clone $auditQuery)
            ->selectRaw('spun_for_date')
            ->selectRaw('COUNT(*) as total_spins')
            ->selectRaw("SUM(CASE WHEN spin_type = 'free' THEN 1 ELSE 0 END) as free_spins")
            ->selectRaw("SUM(CASE WHEN spin_type = 'paid' THEN 1 ELSE 0 END) as paid_spins")
            ->selectRaw("SUM(CASE WHEN reward_type = 'entry_pack' AND user_entry_pack_id IS NOT NULL THEN 1 ELSE 0 END) as entry_pack_entitlements")
            ->selectRaw("SUM(CASE WHEN reward_type = 'subscription' AND user_subscription_id IS NOT NULL THEN 1 ELSE 0 END) as subscription_entitlements")
            ->selectRaw("SUM(CASE WHEN (reward_type = 'entry_pack' AND user_entry_pack_id IS NULL) OR (reward_type = 'subscription' AND user_subscription_id IS NULL) THEN 1 ELSE 0 END) as entitlement_grant_issues")
            ->selectRaw('COALESCE(SUM(CASE WHEN user_entry_pack_id IS NOT NULL OR user_subscription_id IS NOT NULL THEN reward_duration_hours ELSE 0 END), 0) as entitlement_hours')
            ->selectRaw('COALESCE(SUM(spin_cost_coins), 0) as coins_collected')
            ->selectRaw("COALESCE(SUM(CASE WHEN reward_type = 'coins' THEN reward_value_coins ELSE 0 END), 0) as coins_rewarded")
            ->groupBy('spun_for_date')
            ->orderByDesc('spun_for_date')
            ->get();

        return [
            'settings' => $this->publicSettings(),
            'segments' => $segments,
            'spin_audit' => [
                'summary' => $auditSummary,
                'daily' => $dailyBreakdown,
                'spins' => (clone $auditQuery)
                    ->with(['user', 'segment', 'entryPack', 'subscriptionPlan', 'userEntryPack', 'userSubscription'])
                    ->latest('id')
                    ->paginate((int) ($filters['per_page'] ?? 25))
                    ->withQueryString(),
            ],
            'entitlement_summary' => [
                'today' => $this->aggregateSpinQuery(clone $todaySpins),
                'week' => $this->aggregateSpinQuery(clone $weekSpins),
                'week_start' => $weekStart,
                'week_end' => $today,
            ],
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

    private function filteredAdminSpinsQuery(array $filters): Builder
    {
        $query = FortuneWheelSpin::query();
        $search = trim((string) ($filters['q'] ?? ''));

        return $query
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = "%{$search}%";
                $query->where(function (Builder $query) use ($search, $like) {
                    $query
                        ->where('idempotency_key', 'like', $like)
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like));

                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search)->orWhere('user_id', (int) $search);
                    }
                });
            })
            ->when(! empty($filters['spin_type']), fn (Builder $query) => $query->where('spin_type', $filters['spin_type']))
            ->when(! empty($filters['reward_type']), fn (Builder $query) => $query->where('reward_type', $filters['reward_type']))
            ->when(! empty($filters['date_from']), fn (Builder $query) => $query->whereDate('spun_for_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $query) => $query->whereDate('spun_for_date', '<=', $filters['date_to']));
    }

    private function aggregateSpinQuery(Builder $query): array
    {
        $row = $query
            ->selectRaw('COUNT(*) as total_spins')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_players')
            ->selectRaw("SUM(CASE WHEN spin_type = 'free' THEN 1 ELSE 0 END) as free_spins")
            ->selectRaw("SUM(CASE WHEN spin_type = 'paid' THEN 1 ELSE 0 END) as paid_spins")
            ->selectRaw("SUM(CASE WHEN reward_type = 'entry_pack' AND user_entry_pack_id IS NOT NULL THEN 1 ELSE 0 END) as entry_pack_entitlements")
            ->selectRaw("SUM(CASE WHEN reward_type = 'subscription' AND user_subscription_id IS NOT NULL THEN 1 ELSE 0 END) as subscription_entitlements")
            ->selectRaw("SUM(CASE WHEN (reward_type = 'entry_pack' AND user_entry_pack_id IS NULL) OR (reward_type = 'subscription' AND user_subscription_id IS NULL) THEN 1 ELSE 0 END) as entitlement_grant_issues")
            ->selectRaw('COALESCE(SUM(CASE WHEN user_entry_pack_id IS NOT NULL OR user_subscription_id IS NOT NULL THEN reward_duration_hours ELSE 0 END), 0) as entitlement_hours')
            ->selectRaw('COALESCE(SUM(spin_cost_coins), 0) as coins_collected')
            ->selectRaw("COALESCE(SUM(CASE WHEN reward_type = 'coins' THEN reward_value_coins ELSE 0 END), 0) as coins_rewarded")
            ->first();

        $entryPacks = (int) ($row?->entry_pack_entitlements ?? 0);
        $subscriptions = (int) ($row?->subscription_entitlements ?? 0);

        return [
            'total_spins' => (int) ($row?->total_spins ?? 0),
            'unique_players' => (int) ($row?->unique_players ?? 0),
            'free_spins' => (int) ($row?->free_spins ?? 0),
            'paid_spins' => (int) ($row?->paid_spins ?? 0),
            'entry_pack_entitlements' => $entryPacks,
            'subscription_entitlements' => $subscriptions,
            'total_entitlements' => $entryPacks + $subscriptions,
            'entitlement_grant_issues' => (int) ($row?->entitlement_grant_issues ?? 0),
            'entitlement_hours' => (int) ($row?->entitlement_hours ?? 0),
            'coins_collected' => (int) ($row?->coins_collected ?? 0),
            'coins_rewarded' => (int) ($row?->coins_rewarded ?? 0),
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
                'event' => 'FORTUNE_WHEEL_PAYOUT_CREDIT',
                'spin_id' => $spin->id,
                'segment_id' => $segment->id,
            ],
            attributes: [
                'category' => 'game_payout_credit',
                'reference_type' => 'fortune_wheel_spin',
                'reference_id' => $spin->id,
            ],
            description: 'Fortune Wheel coin payout',
        );

        return ['wallet_credit_transaction_id' => $walletCredit->id];
    }

    private function applyEntryPackReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        $pack = EntryPack::query()->findOrFail($segment->entry_pack_id);
        $userPack = app(EntryPackService::class)->grantTimedReward(
            user: $user,
            pack: $pack,
            durationHours: (int) $segment->reward_duration_hours,
            purchaseKey: 'fortune_wheel:'.$spin->id,
        );

        return ['user_entry_pack_id' => $userPack->id];
    }

    private function applySubscriptionReward(User $user, FortuneWheelSegment $segment, FortuneWheelSpin $spin): array
    {
        $durationHours = max(1, (int) $segment->reward_duration_hours);
        $now = now();
        $active = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $base = $active ? $active->ends_at->clone() : $now->clone();
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
            $existingMeta = $active->meta ?? [];
            $active->update([
                'ends_at' => $endsAt,
                'last_purchased_at' => $now,
                'status' => 'active',
                'meta' => array_merge($existingMeta, [
                    'initial_source' => $existingMeta['initial_source'] ?? ($existingMeta['source'] ?? null),
                    'last_extension_source' => 'fortune_wheel',
                    'last_extension_spin_id' => $spin->id,
                    'last_extension_segment_id' => $segment->id,
                    'last_extension_duration_hours' => $durationHours,
                    'last_extended_at' => $now->toIso8601String(),
                ]),
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
