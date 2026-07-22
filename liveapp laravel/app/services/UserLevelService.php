<?php

namespace App\Services;

use App\Models\LevelSpendEvent;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UserLevelService
{
    private const LEVEL_REWARD_FRAMES = [
        1 => 'crimson-crown-laurel',
        11 => 'sapphire-crown-laurel',
        21 => 'amethyst-crown-laurel',
        31 => 'emerald-crown-laurel',
        41 => 'rose-orbit-crown',
        51 => 'ruby-crown-laurel',
        61 => 'azure-crown-laurel',
        71 => 'imperial-violet-crown',
        81 => 'royal-sapphire-crown',
        91 => 'emerald-throne-crown',
    ];

    public function __construct(
        private readonly ProfileFrameService $frames,
    ) {
    }

    /**
     * Categories that should never count toward spend even if a debit somehow appears.
     */
    private array $excludedCategories = [
        'recharge',
        'purchase',
        'earning',
        'refund',
        'bonus',
        'admin_credit',
        'gift_earning',
        'gift_agency_earning',
        'game_bet_debit',
        'adjustment',
    ];

    public function isSpendTransaction(WalletTransaction $transaction): bool
    {
        if ($transaction->type !== 'debit') {
            return false;
        }

        $category = strtolower((string) ($transaction->category ?? ''));
        if (in_array($category, $this->excludedCategories, true)) {
            return false;
        }

        return (int) $transaction->coins > 0;
    }

    public function processWalletTransaction(WalletTransaction|int $transaction): ?array
    {
        $transactionId = $transaction instanceof WalletTransaction ? $transaction->id : $transaction;

        return DB::transaction(function () use ($transactionId) {
            $walletTransaction = WalletTransaction::query()
                ->with('wallet')
                ->lockForUpdate()
                ->find($transactionId);

            if (!$walletTransaction || !$walletTransaction->wallet || !$this->isSpendTransaction($walletTransaction)) {
                return null;
            }

            $user = User::query()
                ->with('level')
                ->lockForUpdate()
                ->find($walletTransaction->wallet->user_id);

            if (!$user) {
                return null;
            }

            $existingEvent = LevelSpendEvent::query()
                ->where('wallet_transaction_id', $walletTransaction->id)
                ->lockForUpdate()
                ->first();

            if ($existingEvent) {
                return $this->progressPayload(
                    $user->fresh('level'),
                    $walletTransaction
                );
            }

            $spendCoins = (int) $walletTransaction->coins;

            try {
                LevelSpendEvent::query()->create([
                    'user_id' => $user->id,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'spend_coins' => $spendCoins,
                    'created_at' => $walletTransaction->created_at ?? now(),
                ]);
            } catch (QueryException $e) {
                $sqlCode = (string) ($e->errorInfo[1] ?? $e->getCode());
                if (!in_array($sqlCode, ['19', '1062', '1555', '2067'], true)) {
                    throw $e;
                }

                return $this->progressPayload(
                    $user->fresh('level'),
                    $walletTransaction
                );
            }

            $oldLevelId = $user->level_id;
            $newLifetimeSpend = (int) $user->lifetime_spend_coins + $spendCoins;
            $newLevel = $this->levelForSpend($newLifetimeSpend);

            $user->forceFill([
                'lifetime_spend_coins' => $newLifetimeSpend,
                'level_id' => $newLevel?->id,
            ])->save();

            if ($newLevel && $oldLevelId !== $newLevel->id) {
                $oldLevel = $oldLevelId ? UserLevel::query()->find($oldLevelId) : null;
                UserLevelHistory::query()->create([
                    'user_id' => $user->id,
                    'old_level_id' => $oldLevelId,
                    'new_level_id' => $newLevel->id,
                    'lifetime_spend_coins' => $newLifetimeSpend,
                    'triggered_by_transaction_id' => $walletTransaction->id,
                ]);

                $this->emitLevelUpRealtime(
                    $user->fresh('level'),
                    $newLevel,
                    $oldLevel,
                    $walletTransaction,
                );
                $this->syncLevelRewardFrame(
                    $user->fresh('level'),
                    $newLevel,
                    $oldLevel,
                    true,
                );
            }

            return $this->progressPayload(
                $user->fresh('level'),
                $walletTransaction
            );
        });
    }

    public function initializeFor(User $user): array
    {
        $level = $user->level ?: $this->levelForSpend((int) $user->lifetime_spend_coins);

        if ($level && ((int) ($user->level_id ?? 0) !== (int) $level->id)) {
            $user->forceFill([
                'level_id' => $level->id,
                'lifetime_spend_coins' => (int) $user->lifetime_spend_coins,
            ])->save();
            $user->setRelation('level', $level);
        }

        if ($level) {
            $this->syncLevelRewardFrame($user->fresh('level'), $level, null, false);
        }

        return $this->progressPayload($user->fresh('level'));
    }

    public function progressPayload(User $user, ?WalletTransaction $transaction = null): array
    {
        $currentLevel = $user->level ?: $this->levelForSpend((int) $user->lifetime_spend_coins);
        $nextLevel = $currentLevel
            ? UserLevel::query()
                ->where('is_active', true)
                ->where('min_spend_coins', '>', (int) $currentLevel->min_spend_coins)
                ->orderBy('min_spend_coins')
                ->first()
            : UserLevel::query()->where('is_active', true)->orderBy('min_spend_coins')->first();

        $currentMin = (int) ($currentLevel->min_spend_coins ?? 0);
        $nextMin = (int) ($nextLevel->min_spend_coins ?? $currentMin);
        $lifetime = (int) $user->lifetime_spend_coins;
        $remaining = $nextLevel ? max(0, $nextMin - $lifetime) : 0;
        $span = max(1, $nextMin - $currentMin);
        $progress = $nextLevel
            ? min(100, max(0, (int) floor((($lifetime - $currentMin) / $span) * 100)))
            : 100;

        return [
            'user_id' => $user->id,
            'level_id' => $currentLevel?->id,
            'level' => (int) ($currentLevel->level ?? 1),
            'level_title' => $currentLevel?->title ?? 'Newbie',
            'badge_icon' => $currentLevel?->badge_icon,
            'badge_color' => $currentLevel?->badge_color,
            'lifetime_spend_coins' => $lifetime,
            'next_level' => $nextLevel?->level,
            'next_level_title' => $nextLevel?->title,
            'next_level_required_spend' => $nextLevel?->min_spend_coins,
            'remaining_spend_to_next_level' => $remaining,
            'progress_percent' => $progress,
            'triggered_by_transaction_id' => $transaction?->id,
        ];
    }

    public function profileProgress(User $user): array
    {
        return $this->progressPayload($user);
    }

    public function levelForSpend(int $spendCoins): ?UserLevel
    {
        return UserLevel::query()
            ->where('is_active', true)
            ->where('min_spend_coins', '<=', max(0, $spendCoins))
            ->orderByDesc('min_spend_coins')
            ->orderByDesc('level')
            ->first();
    }

    public function activeLevels(): Collection
    {
        return UserLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('level')
            ->get();
    }

    public function levelsPayload(): array
    {
        return $this->activeLevels()
            ->map(fn (UserLevel $level) => [
                'id' => (int) $level->id,
                'level' => (int) $level->level,
                'title' => (string) $level->title,
                'min_spend_coins' => (int) $level->min_spend_coins,
                'badge_icon' => $level->badge_icon,
                'badge_color' => $level->badge_color,
                'benefits' => $level->benefits,
                'sort_order' => (int) $level->sort_order,
            ])
            ->values()
            ->all();
    }

    public function recalculate(?User $targetUser = null, bool $dryRun = false): array
    {
        $users = $targetUser
            ? User::query()->whereKey($targetUser->id)->get()
            : User::query()->orderBy('id')->get();

        $report = [
            'dry_run' => $dryRun,
            'users' => [],
            'changed_users' => 0,
        ];

        foreach ($users as $user) {
            $walletId = optional($user->wallet)->id;
            $transactions = $walletId
                ? WalletTransaction::query()
                    ->where('wallet_id', $walletId)
                    ->orderBy('id')
                    ->get()
                : collect();

            $spendTransactions = $transactions->filter(fn (WalletTransaction $tx) => $this->isSpendTransaction($tx));
            $spendIds = $spendTransactions->pluck('id')->all();
            $lifetimeSpend = (int) $spendTransactions->sum('coins');
            $newLevel = $this->levelForSpend($lifetimeSpend);
            $changed = ((int) $user->lifetime_spend_coins !== $lifetimeSpend) || ((int) ($user->level_id ?? 0) !== (int) ($newLevel?->id ?? 0));

            $report['users'][] = [
                'user_id' => $user->id,
                'old_lifetime_spend_coins' => (int) $user->lifetime_spend_coins,
                'new_lifetime_spend_coins' => $lifetimeSpend,
                'old_level_id' => $user->level_id,
                'new_level_id' => $newLevel?->id,
                'changed' => $changed,
            ];

            if (!$changed && !$dryRun) {
                $this->syncSpendEvents($user, $spendTransactions, $spendIds);
                continue;
            }

            if ($changed) {
                $report['changed_users']++;
            }

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($user, $lifetimeSpend, $newLevel, $spendTransactions, $spendIds) {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $oldLevelId = $lockedUser->level_id;

                $lockedUser->forceFill([
                    'lifetime_spend_coins' => $lifetimeSpend,
                    'level_id' => $newLevel?->id,
                ])->save();

                $this->syncSpendEvents($lockedUser, $spendTransactions, $spendIds);

                if ($newLevel && $oldLevelId !== $newLevel->id) {
                    $oldLevel = $oldLevelId ? UserLevel::query()->find($oldLevelId) : null;
                    UserLevelHistory::query()->create([
                        'user_id' => $lockedUser->id,
                        'old_level_id' => $oldLevelId,
                        'new_level_id' => $newLevel->id,
                        'lifetime_spend_coins' => $lifetimeSpend,
                        'triggered_by_transaction_id' => $spendTransactions->last()?->id,
                    ]);

                    if (!$oldLevel || (int) $newLevel->level > (int) $oldLevel->level) {
                        $this->emitLevelUpRealtime(
                            $lockedUser->fresh('level'),
                            $newLevel,
                            $oldLevel,
                            $spendTransactions->last(),
                        );
                    }
                }
            });
        }

        return $report;
    }

    private function syncSpendEvents(User $user, Collection $spendTransactions, array $spendIds): void
    {
        LevelSpendEvent::query()
            ->where('user_id', $user->id)
            ->when($spendIds !== [], fn ($query) => $query->whereNotIn('wallet_transaction_id', $spendIds))
            ->when($spendIds === [], fn ($query) => $query)
            ->delete();

        foreach ($spendTransactions as $transaction) {
            LevelSpendEvent::query()->updateOrCreate(
                ['wallet_transaction_id' => $transaction->id],
                [
                    'user_id' => $user->id,
                    'spend_coins' => (int) $transaction->coins,
                    'created_at' => $transaction->created_at ?? now(),
                ]
            );
        }
    }

    private function emitLevelUpRealtime(
        User $user,
        UserLevel $newLevel,
        ?UserLevel $oldLevel = null,
        ?WalletTransaction $transaction = null,
    ): void {
        try {
            $progress = $this->progressPayload($user, $transaction);
            NotifyUser::send($user->id, [
                'type' => 'level_up',
                'title' => 'Level Up',
                'body' => sprintf('You reached Level %d %s.', (int) $newLevel->level, (string) $newLevel->title),
                'screen' => 'profile',
                'meta' => [
                    'level_id' => (int) $newLevel->id,
                    'level' => (int) $newLevel->level,
                    'level_title' => (string) $newLevel->title,
                    'badge_icon' => $newLevel->badge_icon,
                    'badge_color' => $newLevel->badge_color,
                    'old_level_id' => $oldLevel?->id,
                    'old_level' => $oldLevel?->level,
                    'old_level_title' => $oldLevel?->title,
                    'lifetime_spend_coins' => (int) $progress['lifetime_spend_coins'],
                    'next_level' => $progress['next_level'],
                    'next_level_title' => $progress['next_level_title'],
                    'next_level_required_spend' => $progress['next_level_required_spend'],
                    'remaining_spend_to_next_level' => $progress['remaining_spend_to_next_level'],
                    'progress_percent' => $progress['progress_percent'],
                    'triggered_by_transaction_id' => $transaction?->id,
                ],
            ], [
                'push' => false,
                'persist' => true,
            ]);
        } catch (\Throwable) {
            // Realtime level-up feedback must never block wallet progression.
        }
    }

    private function syncLevelRewardFrame(
        User $user,
        UserLevel $newLevel,
        ?UserLevel $oldLevel = null,
        bool $notify = false,
    ): void {
        $currentBandStart = $this->levelBandStart((int) $newLevel->level);
        $frameSlug = self::LEVEL_REWARD_FRAMES[$currentBandStart] ?? null;
        if ($frameSlug === null) {
            return;
        }

        $oldBandStart = $oldLevel ? $this->levelBandStart((int) $oldLevel->level) : null;
        $shouldNotify = $notify && $oldBandStart !== $currentBandStart;

        try {
            $ownership = $this->frames->grantBySlug(
                $user,
                $frameSlug,
                'level_reward',
                null,
                true,
            );

            if ($shouldNotify) {
                $this->frames->notifyUnlocked(
                    $user,
                    $ownership,
                    'Level reward unlocked',
                    sprintf(
                        '%s unlocked for reaching Level %d.',
                        $ownership->profileFrame?->name ?? 'Profile frame',
                        (int) $newLevel->level,
                    ),
                    false,
                );
            }
        } catch (\Throwable) {
            // Cosmetic rewards must never block progression.
        }
    }

    private function levelBandStart(int $level): int
    {
        $normalized = max(1, min(100, $level));

        return (int) (floor(($normalized - 1) / 10) * 10) + 1;
    }
}
