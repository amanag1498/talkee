<?php

namespace App\Services;

use App\Models\TeenPattiBet;
use App\Models\TeenPattiPayout;
use App\Models\TeenPattiRound;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TeenPattiService
{
    private const CARD_SUITS = ['hearts', 'spades', 'diamonds', 'clubs'];
    private const CARD_VALUES = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'jack', 'queen', 'king', 'ace'];
    private const ACTIVITY_LEASE_KEY = 'games:teen_patti:active_lease';
    private const ACTIVITY_LEASE_SECONDS = 180;

    public function publicSettings(): array
    {
        return [
            'enabled' => $this->enabled(),
            'visible_in_video_room_strip' => $this->visibleInVideoRoomStrip(),
            'fake_bets_enabled' => $this->fakeBetsEnabled(),
            'min_bet' => $this->minBet(),
            'max_bet' => $this->maxBet(),
            'round_duration_seconds' => $this->roundDurationSeconds(),
            'betting_lock_seconds' => $this->bettingLockSeconds(),
            'result_display_seconds' => $this->resultDisplaySeconds(),
            'payout_multiplier' => $this->payoutMultiplier(),
            'winning_strategy_mode' => $this->winningStrategyMode(),
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('games.teen_patti.enabled', false)
            && (bool) config('app_features.platform.android.teen_patti_enabled', false);
    }

    public function visibleInVideoRoomStrip(): bool
    {
        return (bool) config('games.teen_patti.visible_in_video_room_strip', true)
            && (bool) config('app_features.platform.android.video_room_games_enabled', true);
    }

    public function fakeBetsEnabled(): bool
    {
        return (bool) config('games.teen_patti.fake_bets_enabled', false);
    }

    public function minBet(): int
    {
        return max(1, (int) config('games.teen_patti.min_bet', 10));
    }

    public function maxBet(): int
    {
        return max($this->minBet(), (int) config('games.teen_patti.max_bet', 5000));
    }

    public function roundDurationSeconds(): int
    {
        return max(10, (int) config('games.teen_patti.round_duration_seconds', 30));
    }

    public function bettingLockSeconds(): int
    {
        return max(2, min($this->roundDurationSeconds() - 1, (int) config('games.teen_patti.betting_lock_seconds', 5)));
    }

    public function resultDisplaySeconds(): int
    {
        return max(3, (int) config('games.teen_patti.result_display_seconds', 6));
    }

    public function payoutMultiplier(): int
    {
        return max(2, (int) config('games.teen_patti.payout_multiplier', 3));
    }

    public function winningStrategyMode(): string
    {
        $mode = strtolower(trim((string) config('games.teen_patti.winning_strategy_mode', 'probability')));
        return in_array($mode, ['random', 'minimum_bet', 'highest_bet', 'probability'], true)
            ? $mode
            : 'probability';
    }

    public function snapshotForUser(User $user): array
    {
        if (!$this->enabled()) {
            throw new HttpException(403, 'Teen Patti is currently unavailable.');
        }

        $this->touchActivityLease();
        $round = $this->resolveCurrentRound(createIfIdle: true);
        $wallet = WalletService::getOrCreate($user);

        return [
            'settings' => $this->publicSettings(),
            'wallet_balance' => (int) $wallet->balance,
            'round' => $round ? $this->roundPayload($round, $user) : null,
            'history' => $this->historyPayload(8),
            'engine_state' => $round ? 'active' : 'idle',
        ];
    }

    public function publicRoundSnapshot(): array
    {
        if (!$this->enabled()) {
            return [
                'ok' => false,
                'enabled' => false,
            ];
        }

        $this->touchActivityLease();
        $round = $this->resolveCurrentRound(createIfIdle: true);

        return [
            'ok' => true,
            'enabled' => true,
            'settings' => $this->publicSettings(),
            'round' => $round ? $this->roundPayload($round) : null,
            'history' => $this->historyPayload(6),
            'engine_state' => $round ? 'active' : 'idle',
        ];
    }

    public function historyPayload(int $limit = 10): array
    {
        return TeenPattiRound::query()
            ->whereIn('status', ['settled', 'cancelled'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (TeenPattiRound $round) => $this->roundPayload($round))
            ->values()
            ->all();
    }

    public function placeBet(User $user, string $pot, int $amount, ?string $idempotencyKey = null): array
    {
        if (!$this->enabled()) {
            throw new HttpException(403, 'Teen Patti is currently unavailable.');
        }

        $pot = strtoupper(trim($pot));
        if (!in_array($pot, ['A', 'B', 'C'], true)) {
            throw new HttpException(422, 'Invalid pot selection.');
        }
        if ($amount < $this->minBet() || $amount > $this->maxBet()) {
            throw new HttpException(422, "Bet amount must be between {$this->minBet()} and {$this->maxBet()} coins.");
        }

        $this->touchActivityLease();
        $round = $this->resolveCurrentRound(createIfIdle: true);
        if (!$round) {
            throw new HttpException(409, 'Teen Patti is idle. Open the game again and retry.');
        }

        $result = DB::transaction(function () use ($user, $round, $pot, $amount, $idempotencyKey) {
            /** @var TeenPattiRound $lockedRound */
            $lockedRound = TeenPattiRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            if ($lockedRound->status !== 'open' || now()->greaterThanOrEqualTo($lockedRound->locks_at)) {
                throw new HttpException(409, 'Betting is locked for this round.');
            }

            $normalizedKey = $idempotencyKey ? Str::limit(trim($idempotencyKey), 120, '') : null;
            if ($normalizedKey) {
                $existing = TeenPattiBet::query()
                    ->where('teen_patti_round_id', $lockedRound->id)
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $normalizedKey)
                    ->with('walletTransaction')
                    ->first();
                if ($existing) {
                    return [$existing, true];
                }
            }

            WalletService::getOrCreate($user);
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $wallet->balance < $amount) {
                throw new HttpException(422, 'Insufficient wallet balance.');
            }

            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore - $amount;
            $wallet->update(['balance' => $balanceAfter]);

            $bet = TeenPattiBet::query()->create([
                'teen_patti_round_id' => $lockedRound->id,
                'user_id' => $user->id,
                'pot' => $pot,
                'amount' => $amount,
                'status' => 'placed',
                'idempotency_key' => $normalizedKey,
                'placed_at' => now(),
                'meta' => [
                    'round_key' => $lockedRound->round_key,
                ],
            ]);

            $walletTx = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'coins' => $amount,
                'category' => 'game_bet_debit',
                'reference' => 'teen_patti_bet:' . $bet->id,
                'reference_type' => 'teen_patti_bet',
                'reference_id' => $bet->id,
                'description' => "Teen Patti bet on pot {$pot}",
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'game' => 'teen_patti',
                    'bet_id' => $bet->id,
                    'round_key' => $lockedRound->round_key,
                    'pot' => $pot,
                ],
            ]);

            $bet->forceFill(['wallet_transaction_id' => $walletTx->id])->save();

            $column = match ($pot) {
                'A' => 'total_bet_a',
                'B' => 'total_bet_b',
                default => 'total_bet_c',
            };

            $lockedRound->forceFill([
                $column => (int) $lockedRound->{$column} + $amount,
                'total_bets_count' => (int) $lockedRound->total_bets_count + 1,
            ])->save();

            return [$bet->fresh('walletTransaction'), false];
        });

        [$bet, $alreadyProcessed] = $result;
        $freshRound = $this->refreshRoundState(TeenPattiRound::query()->findOrFail($bet->teen_patti_round_id));
        $snapshot = $this->roundPayload($freshRound, $user);

        TeenPattiBroadcaster::broadcast('teen_patti:bet_placed', [
            'round_id' => $freshRound->id,
            'round_key' => $freshRound->round_key,
            'totals' => data_get($snapshot, 'totals'),
            'bet' => [
                'id' => $bet->id,
                'user_id' => $bet->user_id,
                'pot' => $bet->pot,
                'amount' => (int) $bet->amount,
            ],
        ]);

        return [
            'bet' => $this->betPayload($bet),
            'already_processed' => $alreadyProcessed,
            'round' => $snapshot,
            'wallet_balance' => (int) Wallet::query()->where('user_id', $user->id)->value('balance'),
        ];
    }

    public function adminDashboardPayload(): array
    {
        $round = $this->enabled() ? $this->resolveCurrentRound(createIfIdle: false) : null;

        return [
            'settings' => $this->publicSettings(),
            'current_round' => $round ? $this->roundPayload($round) : null,
            'company_summary' => $this->adminCompanySummary(),
            'recent_rounds' => TeenPattiRound::query()->latest('id')->limit(15)->get(),
            'recent_bets' => TeenPattiBet::query()->with(['user', 'round'])->latest('id')->limit(50)->get(),
            'recent_payouts' => TeenPattiPayout::query()->with(['user', 'bet', 'round'])->latest('id')->limit(50)->get(),
        ];
    }

    public function roundsQuery(): Builder
    {
        return TeenPattiRound::query()->latest('id');
    }

    public function betsQuery(): Builder
    {
        return TeenPattiBet::query()
            ->with(['user', 'round', 'walletTransaction'])
            ->latest('id');
    }

    public function payoutsQuery(): Builder
    {
        return TeenPattiPayout::query()
            ->with(['user', 'bet', 'round', 'walletTransaction'])
            ->latest('id');
    }

    public function adminUserReportPayload(array $filters = []): array
    {
        $window = $this->normalizeAdminReportWindow($filters);
        $search = trim((string) ($filters['q'] ?? ''));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));

        $betQuery = TeenPattiBet::query();
        $this->applyAdminTimeWindow($betQuery, 'COALESCE(placed_at, created_at)', $window['start'], $window['end']);

        $payoutQuery = TeenPattiPayout::query();
        $this->applyAdminTimeWindow($payoutQuery, 'COALESCE(settled_at, created_at)', $window['start'], $window['end']);

        $refundQuery = TeenPattiBet::query()->whereNotNull('refunded_at');
        $this->applyAdminTimeWindow($refundQuery, 'COALESCE(refunded_at, updated_at, created_at)', $window['start'], $window['end']);

        $betAgg = (clone $betQuery)
            ->selectRaw('user_id, COUNT(*) as total_bets_count, COALESCE(SUM(amount), 0) as total_bet_amount')
            ->groupBy('user_id');
        $payoutAgg = (clone $payoutQuery)
            ->selectRaw('user_id, COUNT(*) as total_wins_count, COALESCE(SUM(payout_coins), 0) as total_win_amount')
            ->groupBy('user_id');
        $refundAgg = (clone $refundQuery)
            ->selectRaw('user_id, COUNT(*) as total_refunds_count, COALESCE(SUM(amount), 0) as refunded_amount')
            ->groupBy('user_id');

        $activityUsers = (clone $betQuery)
            ->select('user_id')
            ->union((clone $payoutQuery)->select('user_id'))
            ->union((clone $refundQuery)->select('user_id'));

        $reportBase = DB::query()
            ->fromSub($activityUsers, 'activity_users')
            ->join('users', 'users.id', '=', 'activity_users.user_id')
            ->leftJoinSub($betAgg, 'bet_agg', fn ($join) => $join->on('bet_agg.user_id', '=', 'users.id'))
            ->leftJoinSub($payoutAgg, 'payout_agg', fn ($join) => $join->on('payout_agg.user_id', '=', 'users.id'))
            ->leftJoinSub($refundAgg, 'refund_agg', fn ($join) => $join->on('refund_agg.user_id', '=', 'users.id'))
            ->selectRaw("
                users.id as user_id,
                users.name,
                users.email,
                COALESCE(bet_agg.total_bets_count, 0) as total_bets_count,
                COALESCE(bet_agg.total_bet_amount, 0) as total_bet_amount,
                COALESCE(payout_agg.total_wins_count, 0) as total_wins_count,
                COALESCE(payout_agg.total_win_amount, 0) as total_win_amount,
                COALESCE(refund_agg.total_refunds_count, 0) as total_refunds_count,
                COALESCE(refund_agg.refunded_amount, 0) as refunded_amount,
                (COALESCE(bet_agg.total_bet_amount, 0) - COALESCE(payout_agg.total_win_amount, 0) - COALESCE(refund_agg.refunded_amount, 0)) as profit_amount
            ")
            ->distinct();

        if ($search !== '') {
            if (is_numeric($search)) {
                $reportBase->where('users.id', (int) $search);
            } else {
                $reportBase->where(function ($query) use ($search) {
                    $query
                        ->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%");
                });
            }
        }

        $summary = DB::query()
            ->fromSub(clone $reportBase, 'report_rows')
            ->selectRaw('
                COUNT(*) as active_users_count,
                COALESCE(SUM(total_bet_amount), 0) as total_bet_amount,
                COALESCE(SUM(total_win_amount), 0) as total_win_amount,
                COALESCE(SUM(refunded_amount), 0) as refunded_amount,
                COALESCE(SUM(profit_amount), 0) as profit_amount
            ')
            ->first();

        $rows = $reportBase
            ->orderByDesc('profit_amount')
            ->orderByDesc('total_bet_amount')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'filters' => [
                'period' => $window['period'],
                'start_date' => $window['start']->toDateString(),
                'end_date' => $window['end']->toDateString(),
                'label' => $window['label'],
                'q' => $search,
                'per_page' => $perPage,
            ],
            'summary' => [
                'active_users_count' => (int) ($summary->active_users_count ?? 0),
                'total_bet_amount' => (int) ($summary->total_bet_amount ?? 0),
                'total_win_amount' => (int) ($summary->total_win_amount ?? 0),
                'refunded_amount' => (int) ($summary->refunded_amount ?? 0),
                'profit_amount' => (int) ($summary->profit_amount ?? 0),
            ],
            'rows' => $rows,
        ];
    }

    public function tick(?TeenPattiRound $round = null): ?TeenPattiRound
    {
        if (!$this->enabled()) {
            throw new HttpException(403, 'Teen Patti is currently unavailable.');
        }

        if ($round) {
            return $this->refreshRoundState($round->fresh());
        }

        return $this->resolveCurrentRound(createIfIdle: false);
    }

    public function reconcileRound(TeenPattiRound $round): array
    {
        $refreshed = DB::transaction(function () use ($round) {
            /** @var TeenPattiRound $lockedRound */
            $lockedRound = TeenPattiRound::query()
                ->with(['bets.user', 'bets.walletTransaction', 'bets.payout', 'payouts'])
                ->whereKey($round->id)
                ->lockForUpdate()
                ->firstOrFail();

            $bets = $lockedRound->bets->whereNull('refunded_at');
            $actualTotals = [
                'A' => (int) $bets->where('pot', 'A')->sum('amount'),
                'B' => (int) $bets->where('pot', 'B')->sum('amount'),
                'C' => (int) $bets->where('pot', 'C')->sum('amount'),
            ];
            $actualCount = (int) $bets->count();

            $lockedRound->forceFill([
                'total_bet_a' => $actualTotals['A'],
                'total_bet_b' => $actualTotals['B'],
                'total_bet_c' => $actualTotals['C'],
                'total_bets_count' => $actualCount,
            ])->save();

            if (in_array($lockedRound->status, ['open', 'locked'], true) && now()->greaterThanOrEqualTo($lockedRound->ends_at)) {
                return $this->settleRound($lockedRound);
            }

            if ($lockedRound->status === 'settled' && in_array($lockedRound->winning_pot, ['A', 'B', 'C'], true)) {
                foreach ($lockedRound->bets as $bet) {
                    $isWinner = $bet->pot === $lockedRound->winning_pot;
                    $expectedStatus = $isWinner ? 'won' : 'lost';
                    $expectedPayoutCoins = $isWinner ? ((int) $bet->amount * $this->payoutMultiplier()) : 0;

                    if ($bet->status !== $expectedStatus || (int) $bet->payout_coins !== $expectedPayoutCoins) {
                        $bet->forceFill([
                            'status' => $expectedStatus,
                            'payout_coins' => $expectedPayoutCoins,
                            'settled_at' => $bet->settled_at ?? now(),
                        ])->save();
                    }

                    if ($isWinner) {
                        $this->creditPayoutForBet($lockedRound, $bet, $lockedRound->winning_pot);
                    }
                }
            }

            return $lockedRound->fresh(['bets.user', 'bets.walletTransaction', 'bets.payout', 'payouts']);
        });

        return [
            'round' => $this->roundPayload($refreshed),
            'display_until' => $this->displayUntil($refreshed)->toIso8601String(),
            'next_round_ready' => now()->greaterThanOrEqualTo($this->displayUntil($refreshed)),
        ];
    }

    public function refundBet(TeenPattiBet $bet, ?string $note = null): TeenPattiBet
    {
        return DB::transaction(function () use ($bet, $note) {
            /** @var TeenPattiBet $lockedBet */
            $lockedBet = TeenPattiBet::query()
                ->with(['user', 'round', 'walletTransaction', 'payout'])
                ->whereKey($bet->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBet->refunded_at) {
                return $lockedBet;
            }

            if ($lockedBet->payout()->exists()) {
                throw new HttpException(409, 'Winning bets with credited payouts cannot be refunded from this action.');
            }

            $user = $lockedBet->user;
            WalletService::getOrCreate($user);
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = (int) $wallet->balance;
            $refundCoins = (int) $lockedBet->amount;
            $balanceAfter = $balanceBefore + $refundCoins;
            $wallet->update(['balance' => $balanceAfter]);

            $walletTx = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'coins' => $refundCoins,
                'category' => 'game_refund_credit',
                'reference' => 'teen_patti_bet:' . $lockedBet->id,
                'reference_type' => 'teen_patti_bet',
                'reference_id' => $lockedBet->id,
                'description' => 'Teen Patti bet refund',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'game' => 'teen_patti',
                    'bet_id' => $lockedBet->id,
                    'round_key' => $lockedBet->round?->round_key,
                    'note' => $note,
                ],
            ]);

            $lockedBet->forceFill([
                'status' => 'refunded',
                'refunded_at' => now(),
                'meta' => array_filter([
                    ...($lockedBet->meta ?? []),
                    'refund_wallet_transaction_id' => $walletTx->id,
                    'refund_note' => $note,
                ], static fn ($value) => $value !== null && $value !== ''),
            ])->save();

            $remainingBets = TeenPattiBet::query()
                ->where('teen_patti_round_id', $lockedBet->teen_patti_round_id)
                ->whereNull('refunded_at')
                ->get();
            $lockedBet->round->forceFill([
                'total_bet_a' => (int) $remainingBets->where('pot', 'A')->sum('amount'),
                'total_bet_b' => (int) $remainingBets->where('pot', 'B')->sum('amount'),
                'total_bet_c' => (int) $remainingBets->where('pot', 'C')->sum('amount'),
                'total_bets_count' => (int) $remainingBets->count(),
            ])->save();

            TeenPattiBroadcaster::broadcast('teen_patti:bet_refunded', [
                'round_id' => $lockedBet->teen_patti_round_id,
                'round_key' => $lockedBet->round?->round_key,
                'bet_id' => $lockedBet->id,
                'user_id' => $lockedBet->user_id,
                'amount' => $refundCoins,
            ]);

            return $lockedBet->fresh(['user', 'round', 'walletTransaction', 'payout']);
        });
    }

    public function ensureCurrentRound(): TeenPattiRound
    {
        if (!$this->enabled()) {
            throw new HttpException(403, 'Teen Patti is currently unavailable.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $round = TeenPattiRound::query()->latest('id')->first();
            if (!$round) {
                return $this->createRound(CarbonImmutable::now());
            }

            $round = $this->refreshRoundState($round);

            if (in_array($round->status, ['open', 'locked'], true)) {
                return $round;
            }

            $displayUntil = $this->displayUntil($round);
            if (now()->greaterThanOrEqualTo($displayUntil)) {
                $nextStart = $displayUntil;
                if ($nextStart->lessThan(CarbonImmutable::now())) {
                    $nextStart = CarbonImmutable::now();
                }

                return $this->createRound($nextStart);
            }

            return $round;
        }

        return TeenPattiRound::query()->latest('id')->firstOrFail();
    }

    public function pruneIdleRounds(int $hours = 24): int
    {
        $cutoff = now()->subHours(max(1, $hours));

        return TeenPattiRound::query()
            ->whereIn('status', ['settled', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->where('total_bets_count', 0)
            ->doesntHave('bets')
            ->doesntHave('payouts')
            ->delete();
    }

    private function resolveCurrentRound(bool $createIfIdle): ?TeenPattiRound
    {
        if (!$this->enabled()) {
            throw new HttpException(403, 'Teen Patti is currently unavailable.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $round = TeenPattiRound::query()->latest('id')->first();
            if (!$round) {
                return $createIfIdle || $this->hasRecentActivityLease()
                    ? $this->createRound(CarbonImmutable::now())
                    : null;
            }

            $round = $this->refreshRoundState($round);

            if (in_array($round->status, ['open', 'locked'], true)) {
                return $round;
            }

            $displayUntil = $this->displayUntil($round);
            if (now()->greaterThanOrEqualTo($displayUntil)) {
                if (!$createIfIdle && !$this->hasRecentActivityLease()) {
                    return $round;
                }

                $nextStart = $displayUntil;
                if ($nextStart->lessThan(CarbonImmutable::now())) {
                    $nextStart = CarbonImmutable::now();
                }

                return $this->createRound($nextStart);
            }

            return $round;
        }

        return TeenPattiRound::query()->latest('id')->first();
    }

    private function touchActivityLease(): void
    {
        Cache::put(
            self::ACTIVITY_LEASE_KEY,
            CarbonImmutable::now()->toIso8601String(),
            now()->addSeconds(self::ACTIVITY_LEASE_SECONDS),
        );
    }

    private function hasRecentActivityLease(): bool
    {
        return Cache::has(self::ACTIVITY_LEASE_KEY);
    }

    public function refreshRoundState(TeenPattiRound $round): TeenPattiRound
    {
        $now = CarbonImmutable::now();

        if ($round->status === 'open' && $now->greaterThanOrEqualTo($round->locks_at)) {
            $round = DB::transaction(function () use ($round) {
                /** @var TeenPattiRound $locked */
                $locked = TeenPattiRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'open' && now()->greaterThanOrEqualTo($locked->locks_at)) {
                    $locked->forceFill(['status' => 'locked'])->save();
                    TeenPattiBroadcaster::broadcast('teen_patti:round_locked', [
                        'round_id' => $locked->id,
                        'round_key' => $locked->round_key,
                    ]);
                }
                return $locked->fresh();
            });
        }

        if (in_array($round->status, ['open', 'locked'], true) && $now->greaterThanOrEqualTo($round->ends_at)) {
            $round = $this->settleRound($round);
        }

        return $round->fresh();
    }

    public function settleRound(TeenPattiRound $round): TeenPattiRound
    {
        return DB::transaction(function () use ($round) {
            /** @var TeenPattiRound $lockedRound */
            $lockedRound = TeenPattiRound::query()
                ->with(['bets.user', 'bets.walletTransaction'])
                ->whereKey($round->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedRound->status, ['settled', 'cancelled'], true)) {
                return $lockedRound;
            }

            $bets = $lockedRound->bets;
            $winner = $this->determineWinningPot($lockedRound, $bets);
            $cards = $this->buildCardReveal();

            foreach ($bets as $bet) {
                $isWinner = $bet->pot === $winner;
                $payoutCoins = $isWinner ? ((int) $bet->amount * $this->payoutMultiplier()) : 0;

                $bet->forceFill([
                    'status' => $isWinner ? 'won' : 'lost',
                    'payout_coins' => $payoutCoins,
                    'settled_at' => now(),
                ])->save();

                if ($isWinner) {
                    $this->creditPayoutForBet($lockedRound, $bet, $winner);
                }
            }

            $lockedRound->forceFill([
                'status' => 'settled',
                'winning_pot' => $winner,
                'winning_strategy' => $this->winningStrategyMode(),
                'winning_hand' => $cards['winning_hand'],
                'losing_hand_one' => $cards['losing_hand_one'],
                'losing_hand_two' => $cards['losing_hand_two'],
                'settled_at' => now(),
            ])->save();

            $payload = $this->roundPayload($lockedRound->fresh());
            TeenPattiBroadcaster::broadcast('teen_patti:round_settled', [
                'round_id' => $lockedRound->id,
                'round_key' => $lockedRound->round_key,
                'snapshot' => $payload,
            ]);

            return $lockedRound->fresh();
        });
    }

    private function createRound(CarbonImmutable $startsAt): TeenPattiRound
    {
        $lockAt = $startsAt->addSeconds($this->roundDurationSeconds());
        $endsAt = $lockAt->addSeconds($this->bettingLockSeconds());
        $displayUntil = $endsAt->addSeconds($this->resultDisplaySeconds());

        $round = TeenPattiRound::query()->create([
            'round_key' => 'tpr_' . Str::lower((string) Str::ulid()),
            'status' => 'open',
            'starts_at' => $startsAt,
            'locks_at' => $lockAt,
            'ends_at' => $endsAt,
            'meta' => [
                'round_duration_seconds' => $this->roundDurationSeconds(),
                'betting_lock_seconds' => $this->bettingLockSeconds(),
                'result_display_seconds' => $this->resultDisplaySeconds(),
                'display_until' => $displayUntil->toIso8601String(),
            ],
        ]);

        TeenPattiBroadcaster::broadcast('teen_patti:round_started', [
            'round_id' => $round->id,
            'round_key' => $round->round_key,
            'starts_at' => $round->starts_at?->toIso8601String(),
            'locks_at' => $round->locks_at?->toIso8601String(),
            'ends_at' => $round->ends_at?->toIso8601String(),
        ]);

        return $round;
    }

    private function determineWinningPot(TeenPattiRound $round, Collection $bets): string
    {
        $totals = [
            'A' => (int) $round->total_bet_a,
            'B' => (int) $round->total_bet_b,
            'C' => (int) $round->total_bet_c,
        ];

        $mode = $this->winningStrategyMode();
        if ($bets->isEmpty()) {
            return ['A', 'B', 'C'][random_int(0, 2)];
        }

        return match ($mode) {
            'minimum_bet' => collect($totals)->sort()->keys()->first(),
            'highest_bet' => collect($totals)->sortDesc()->keys()->first(),
            'probability' => $this->probabilityWeightedPot($totals),
            default => ['A', 'B', 'C'][random_int(0, 2)],
        };
    }

    private function probabilityWeightedPot(array $totals): string
    {
        $highestPot = collect($totals)->sortDesc()->keys()->first();
        $others = collect(['A', 'B', 'C'])->reject(fn ($pot) => $pot === $highestPot)->values();
        $roll = random_int(1, 100);

        if ($roll <= 20) {
            return $highestPot;
        }

        return $roll <= 60 ? $others[0] : $others[1];
    }

    private function roundPayload(TeenPattiRound $round, ?User $viewer = null): array
    {
        $round->loadMissing(['bets.user', 'payouts']);
        $now = CarbonImmutable::now();
        $phase = match (true) {
            $round->status === 'settled' => 'result',
            $round->status === 'cancelled' => 'cancelled',
            $now->greaterThanOrEqualTo($round->locks_at) => 'locked',
            default => 'betting',
        };

        $viewerBets = $viewer
            ? $round->bets->where('user_id', $viewer->id)->values()->map(fn (TeenPattiBet $bet) => $this->betPayload($bet))->all()
            : [];

        $countdownTarget = match ($phase) {
            'betting' => CarbonImmutable::parse($round->locks_at),
            'locked' => CarbonImmutable::parse($round->ends_at),
            default => $this->displayUntil($round),
        };
        $countdownSeconds = max(0, $now->diffInSeconds($countdownTarget, false));
        $realTotals = [
            'A' => (int) $round->total_bet_a,
            'B' => (int) $round->total_bet_b,
            'C' => (int) $round->total_bet_c,
        ];
        $fakeTotals = $this->fakeBetsEnabled()
            ? $this->fakeTotalsForRound($round, $phase)
            : ['A' => 0, 'B' => 0, 'C' => 0];
        $displayTotals = [
            'A' => $realTotals['A'] + $fakeTotals['A'],
            'B' => $realTotals['B'] + $fakeTotals['B'],
            'C' => $realTotals['C'] + $fakeTotals['C'],
        ];

        return [
            'id' => $round->id,
            'round_key' => $round->round_key,
            'status' => $round->status,
            'phase' => $phase,
            'starts_at' => optional($round->starts_at)->toIso8601String(),
            'locks_at' => optional($round->locks_at)->toIso8601String(),
            'ends_at' => optional($round->ends_at)->toIso8601String(),
            'settled_at' => optional($round->settled_at)->toIso8601String(),
            'display_until' => $this->displayUntil($round)->toIso8601String(),
            'winning_pot' => $round->winning_pot,
            'winning_hand' => $round->winning_hand ?? [],
            'losing_hand_one' => $round->losing_hand_one ?? [],
            'losing_hand_two' => $round->losing_hand_two ?? [],
            'countdown_seconds' => $countdownSeconds,
            'totals' => $displayTotals,
            'real_totals' => $realTotals,
            'fake_totals' => $fakeTotals,
            'total_bets_count' => (int) $round->total_bets_count,
            'participant_count' => (int) $round->bets->pluck('user_id')->unique()->count(),
            'payout_multiplier' => $this->payoutMultiplier(),
            'viewer_bets' => $viewerBets,
        ];
    }

    private function displayUntil(TeenPattiRound $round): CarbonImmutable
    {
        $displayUntil = data_get($round->meta, 'display_until');
        if (is_string($displayUntil) && trim($displayUntil) !== '') {
            return CarbonImmutable::parse($displayUntil);
        }

        return CarbonImmutable::parse($round->ends_at);
    }

    private function creditPayoutForBet(TeenPattiRound $round, TeenPattiBet $bet, string $winner): void
    {
        $existingPayout = TeenPattiPayout::query()
            ->where('teen_patti_bet_id', $bet->id)
            ->first();

        if ($existingPayout) {
            return;
        }

        $payoutCoins = (int) $bet->payout_coins;
        if ($payoutCoins <= 0) {
            return;
        }

        $user = $bet->user;
        WalletService::getOrCreate($user);
        $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $balanceBefore = (int) $wallet->balance;
        $balanceAfter = $balanceBefore + $payoutCoins;
        $wallet->update(['balance' => $balanceAfter]);

        $walletTx = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'coins' => $payoutCoins,
            'category' => 'game_payout_credit',
            'reference' => 'teen_patti_bet:' . $bet->id,
            'reference_type' => 'teen_patti_bet',
            'reference_id' => $bet->id,
            'description' => "Teen Patti payout for pot {$winner}",
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'meta' => [
                'game' => 'teen_patti',
                'bet_id' => $bet->id,
                'round_key' => $round->round_key,
                'winning_pot' => $winner,
            ],
        ]);

        TeenPattiPayout::query()->create([
            'teen_patti_round_id' => $round->id,
            'teen_patti_bet_id' => $bet->id,
            'user_id' => $user->id,
            'wallet_transaction_id' => $walletTx->id,
            'payout_coins' => $payoutCoins,
            'status' => 'credited',
            'settled_at' => now(),
            'meta' => [
                'winning_pot' => $winner,
            ],
        ]);
    }

    private function betPayload(TeenPattiBet $bet): array
    {
        return [
            'id' => $bet->id,
            'round_id' => $bet->teen_patti_round_id,
            'user_id' => $bet->user_id,
            'pot' => $bet->pot,
            'amount' => (int) $bet->amount,
            'status' => $bet->status,
            'payout_coins' => (int) $bet->payout_coins,
            'placed_at' => optional($bet->placed_at)->toIso8601String(),
            'settled_at' => optional($bet->settled_at)->toIso8601String(),
        ];
    }

    private function buildCardReveal(): array
    {
        return [
            'winning_hand' => $this->drawCards(3),
            'losing_hand_one' => $this->drawCards(3),
            'losing_hand_two' => $this->drawCards(3),
        ];
    }

    private function fakeTotalsForRound(TeenPattiRound $round, string $phase): array
    {
        $seed = sprintf('%s|%s|%s', $round->round_key, $round->starts_at?->timestamp ?? 0, $phase);
        $maxBet = max($this->minBet(), $this->maxBet());
        $band = max($this->minBet(), (int) floor($maxBet * 1.8));

        return [
            'A' => $this->fakePotValue($seed . '|A', $band),
            'B' => $this->fakePotValue($seed . '|B', $band),
            'C' => $this->fakePotValue($seed . '|C', $band),
        ];
    }

    private function fakePotValue(string $seed, int $band): int
    {
        $hash = abs(crc32($seed));
        $multiplier = 3 + ($hash % 9);
        $step = max($this->minBet(), (int) floor($band / 6));
        return $multiplier * $step;
    }

    private function drawCards(int $count): array
    {
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $cards[] = self::CARD_VALUES[random_int(0, count(self::CARD_VALUES) - 1)]
                . '_of_'
                . self::CARD_SUITS[random_int(0, count(self::CARD_SUITS) - 1)]
                . '.png';
        }

        return $cards;
    }

    private function normalizeAdminReportWindow(array $filters): array
    {
        $period = strtolower(trim((string) ($filters['period'] ?? '7d')));
        $allowed = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'];
        if (!in_array($period, $allowed, true)) {
            $period = '7d';
        }

        $now = CarbonImmutable::now();
        $startInput = trim((string) ($filters['start_date'] ?? ''));
        $endInput = trim((string) ($filters['end_date'] ?? ''));

        [$start, $end, $label] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay(), 'Today'],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay(), 'Last 30 days'],
            'this_month' => [$now->startOfMonth(), $now->endOfDay(), 'This month'],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
                'Last month',
            ],
            'custom' => [
                $this->parseAdminDate($startInput, $now->subDays(6)->startOfDay()),
                $this->parseAdminDate($endInput, $now->endOfDay(), endOfDay: true),
                'Custom range',
            ],
            default => [$now->subDays(6)->startOfDay(), $now->endOfDay(), 'Last 7 days'],
        };

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return [
            'period' => $period,
            'start' => $start->startOfDay(),
            'end' => $end->endOfDay(),
            'label' => $label,
        ];
    }

    private function parseAdminDate(string $value, CarbonImmutable $fallback, bool $endOfDay = false): CarbonImmutable
    {
        if ($value === '') {
            return $endOfDay ? $fallback->endOfDay() : $fallback->startOfDay();
        }

        try {
            $date = CarbonImmutable::parse($value);
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return $endOfDay ? $fallback->endOfDay() : $fallback->startOfDay();
        }
    }

    private function applyAdminTimeWindow(Builder $query, string $expression, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->whereBetween(DB::raw($expression), [$start->toDateTimeString(), $end->toDateTimeString()]);
    }

    private function adminCompanySummary(): array
    {
        $window = $this->normalizeAdminReportWindow(['period' => '30d']);

        $betQuery = TeenPattiBet::query();
        $this->applyAdminTimeWindow($betQuery, 'COALESCE(placed_at, created_at)', $window['start'], $window['end']);

        $payoutQuery = TeenPattiPayout::query();
        $this->applyAdminTimeWindow($payoutQuery, 'COALESCE(settled_at, created_at)', $window['start'], $window['end']);

        $refundQuery = TeenPattiBet::query()->whereNotNull('refunded_at');
        $this->applyAdminTimeWindow($refundQuery, 'COALESCE(refunded_at, updated_at, created_at)', $window['start'], $window['end']);

        $totalBetAmount = (int) ((clone $betQuery)->sum('amount') ?? 0);
        $totalWinAmount = (int) ((clone $payoutQuery)->sum('payout_coins') ?? 0);
        $refundedAmount = (int) ((clone $refundQuery)->sum('amount') ?? 0);

        return [
            'label' => $window['label'],
            'start_date' => $window['start']->toDateString(),
            'end_date' => $window['end']->toDateString(),
            'total_bet_amount' => $totalBetAmount,
            'total_win_amount' => $totalWinAmount,
            'refunded_amount' => $refundedAmount,
            'profit_amount' => $totalBetAmount - $totalWinAmount - $refundedAmount,
        ];
    }
}
