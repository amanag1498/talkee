<?php

namespace App\Services;

use App\Models\SevenUpDownBet;
use App\Models\SevenUpDownFinancialAccount;
use App\Models\SevenUpDownFinancialLedgerEntry;
use App\Models\SevenUpDownPayout;
use App\Models\SevenUpDownRound;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SevenUpDownService
{
    private const POTS = ['DOWN', 'SEVEN', 'UP'];

    private const ACTIVITY_LEASE_KEY = 'games:seven_up_down:active_lease';

    private const ACTIVITY_LEASE_SECONDS = 180;

    public function __construct(private SevenUpDownFinancialService $financials) {}

    public function publicSettings(): array
    {
        return [
            'display_name' => 'Lucky 7',
            'enabled' => $this->enabled(),
            'visible_in_video_room_strip' => $this->visibleInVideoRoomStrip(),
            'fake_bets_enabled' => $this->fakeBetsEnabled(),
            'min_bet' => $this->minBet(),
            'max_bet' => $this->maxBet(),
            'round_duration_seconds' => $this->roundDurationSeconds(),
            'betting_lock_seconds' => $this->bettingLockSeconds(),
            'result_display_seconds' => $this->resultDisplaySeconds(),
            'winning_strategy_mode' => $this->winningStrategyMode(),
            'pot_multipliers' => $this->potMultipliers(),
            'outcome_weights' => $this->outcomeWeights(),
            'rules' => [
                'DOWN' => ['min_total' => 2, 'max_total' => 6, 'dice_combinations' => 15],
                'SEVEN' => ['min_total' => 7, 'max_total' => 7, 'dice_combinations' => 6],
                'UP' => ['min_total' => 8, 'max_total' => 12, 'dice_combinations' => 15],
                'payout_type' => 'total_return_including_stake',
                'result_authority' => 'backend_persisted_dice',
            ],
        ];
    }

    public function enabled(): bool
    {
        return (bool) config('games.seven_up_down.enabled', false)
            && (
                (bool) config('app_features.platform.android.seven_up_down_enabled', false)
                || (bool) config('app_features.platform.ios.seven_up_down_enabled', false)
            );
    }

    public function visibleInVideoRoomStrip(): bool
    {
        return (bool) config('games.seven_up_down.visible_in_video_room_strip', true)
            && (
                (bool) config('app_features.platform.android.video_room_games_enabled', true)
                || (bool) config('app_features.platform.ios.video_room_games_enabled', false)
            );
    }

    public function fakeBetsEnabled(): bool
    {
        return (bool) config('games.seven_up_down.fake_bets_enabled', false);
    }

    public function minBet(): int
    {
        return max(1, (int) config('games.seven_up_down.min_bet', 10));
    }

    public function maxBet(): int
    {
        return max($this->minBet(), (int) config('games.seven_up_down.max_bet', 5000));
    }

    public function roundDurationSeconds(): int
    {
        return max(10, (int) config('games.seven_up_down.round_duration_seconds', 30));
    }

    public function bettingLockSeconds(): int
    {
        return max(2, min($this->roundDurationSeconds() - 1, (int) config('games.seven_up_down.betting_lock_seconds', 5)));
    }

    public function resultDisplaySeconds(): int
    {
        return max(3, (int) config('games.seven_up_down.result_display_seconds', 6));
    }

    public function winningStrategyMode(): string
    {
        $mode = strtolower(trim((string) config('games.seven_up_down.winning_strategy_mode', 'probability')));

        return in_array($mode, ['random', 'minimum_bet', 'highest_bet', 'probability', 'treasury_affordable'], true)
            ? $mode
            : 'probability';
    }

    public function potMultipliers(): array
    {
        return [
            'DOWN' => max(2, (int) config('games.seven_up_down.multiplier_down', 3)),
            'SEVEN' => max(2, (int) config('games.seven_up_down.multiplier_seven', 4)),
            'UP' => max(2, (int) config('games.seven_up_down.multiplier_up', 3)),
        ];
    }

    public function outcomeWeights(): array
    {
        return [
            'DOWN' => max(1, (int) config('games.seven_up_down.weight_down', 15)),
            'SEVEN' => max(1, (int) config('games.seven_up_down.weight_seven', 6)),
            'UP' => max(1, (int) config('games.seven_up_down.weight_up', 15)),
        ];
    }

    public function snapshotForUser(User $user): array
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Lucky 7 is currently unavailable.');
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
        if (! $this->enabled()) {
            return ['ok' => false, 'enabled' => false];
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
        return SevenUpDownRound::query()
            ->whereIn('status', ['settled', 'cancelled'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SevenUpDownRound $round) => $this->roundPayload($round))
            ->values()
            ->all();
    }

    public function placeBet(User $user, string $pot, int $amount, ?string $idempotencyKey = null): array
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Lucky 7 is currently unavailable.');
        }

        $pot = strtoupper(trim($pot));
        if (! in_array($pot, self::POTS, true)) {
            throw new HttpException(422, 'Invalid pot selection.');
        }
        if ($amount < $this->minBet() || $amount > $this->maxBet()) {
            throw new HttpException(422, "Bet amount must be between {$this->minBet()} and {$this->maxBet()} coins.");
        }

        $this->touchActivityLease();
        $round = $this->resolveCurrentRound(createIfIdle: true);
        if (! $round) {
            throw new HttpException(409, 'Lucky 7 is idle. Open the game again and retry.');
        }
        $multipliers = (array) data_get($round->meta, 'pot_multipliers', $this->potMultipliers());

        [$bet, $alreadyProcessed] = DB::transaction(function () use ($user, $round, $pot, $amount, $idempotencyKey, $multipliers) {
            /** @var SevenUpDownRound $lockedRound */
            $lockedRound = SevenUpDownRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            if ($lockedRound->status !== 'open' || now()->greaterThanOrEqualTo($lockedRound->locks_at)) {
                throw new HttpException(409, 'Betting is locked for this round.');
            }

            $normalizedKey = $idempotencyKey ? Str::limit(trim($idempotencyKey), 120, '') : null;
            if ($normalizedKey) {
                $existing = SevenUpDownBet::query()
                    ->where('seven_up_down_round_id', $lockedRound->id)
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $normalizedKey)
                    ->with('walletTransaction')
                    ->first();
                if ($existing) {
                    return [$existing, true];
                }
            }

            WalletService::getOrCreate($user);
            $wallet = Wallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ((int) $wallet->balance < $amount) {
                throw new HttpException(422, 'Insufficient wallet balance.');
            }

            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore - $amount;
            $wallet->update(['balance' => $balanceAfter]);

            $bet = SevenUpDownBet::query()->create([
                'seven_up_down_round_id' => $lockedRound->id,
                'user_id' => $user->id,
                'pot' => $pot,
                'amount' => $amount,
                'multiplier' => $multipliers[$pot],
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
                'reference' => 'seven_up_down_bet:'.$bet->id,
                'reference_type' => 'seven_up_down_bet',
                'reference_id' => $bet->id,
                'description' => "Lucky 7 bet on pot {$pot}",
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'game' => 'seven_up_down',
                    'bet_id' => $bet->id,
                    'round_key' => $lockedRound->round_key,
                    'pot' => $pot,
                ],
            ]);

            $bet->forceFill(['wallet_transaction_id' => $walletTx->id])->save();
            $this->financials->recordBet($bet);
            $this->incrementRoundPot($lockedRound, $pot, $amount);

            return [$bet->fresh('walletTransaction'), false];
        });

        $freshRound = $this->refreshRoundState(SevenUpDownRound::query()->findOrFail($bet->seven_up_down_round_id));
        $snapshot = $this->roundPayload($freshRound, $user);

        SevenUpDownBroadcaster::broadcast('seven_up_down:bet_placed', [
            'round_id' => $freshRound->id,
            'round_key' => $freshRound->round_key,
            'totals' => data_get($snapshot, 'totals'),
            'bet' => [
                'id' => $bet->id,
                'user_id' => $bet->user_id,
                'pot' => $bet->pot,
                'amount' => (int) $bet->amount,
                'multiplier' => (int) $bet->multiplier,
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
            'financial_account' => $this->adminFinancialAccountPayload(),
            'recent_financial_ledger_entries' => $this->adminFinancialLedgerEntries(),
            'recent_rounds' => SevenUpDownRound::query()->latest('id')->limit(15)->get(),
            'recent_bets' => SevenUpDownBet::query()->with(['user', 'round'])->latest('id')->limit(50)->get(),
            'recent_payouts' => SevenUpDownPayout::query()->with(['user', 'bet', 'round'])->latest('id')->limit(50)->get(),
        ];
    }

    public function roundsQuery(): Builder
    {
        return SevenUpDownRound::query()->latest('id');
    }

    public function betsQuery(): Builder
    {
        return SevenUpDownBet::query()->with(['user', 'round', 'walletTransaction'])->latest('id');
    }

    public function payoutsQuery(): Builder
    {
        return SevenUpDownPayout::query()->with(['user', 'bet', 'round', 'walletTransaction'])->latest('id');
    }

    public function adminUserReportPayload(array $filters = []): array
    {
        $window = $this->normalizeAdminReportWindow($filters);
        $search = trim((string) ($filters['q'] ?? ''));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));

        $betQuery = SevenUpDownBet::query();
        $this->applyAdminTimeWindow($betQuery, 'COALESCE(placed_at, created_at)', $window['start'], $window['end']);

        $payoutQuery = SevenUpDownPayout::query();
        $this->applyAdminTimeWindow($payoutQuery, 'COALESCE(settled_at, created_at)', $window['start'], $window['end']);

        $refundQuery = SevenUpDownBet::query()->whereNotNull('refunded_at');
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
            ->selectRaw('
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
            ')
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

    public function tick(?SevenUpDownRound $round = null): ?SevenUpDownRound
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Lucky 7 is currently unavailable.');
        }

        if ($round) {
            return $this->refreshRoundState($round->fresh());
        }

        return $this->resolveCurrentRound(createIfIdle: false);
    }

    public function reconcileRound(SevenUpDownRound $round): array
    {
        $refreshed = DB::transaction(function () use ($round) {
            /** @var SevenUpDownRound $lockedRound */
            $lockedRound = SevenUpDownRound::query()
                ->with(['bets.user', 'bets.walletTransaction', 'bets.payout', 'payouts'])
                ->whereKey($round->id)
                ->lockForUpdate()
                ->firstOrFail();

            $bets = $lockedRound->bets->whereNull('refunded_at');
            $actualTotals = $this->sumPotTotals($bets);

            $lockedRound->forceFill([
                'total_bet_down' => $actualTotals['DOWN'],
                'total_bet_seven' => $actualTotals['SEVEN'],
                'total_bet_up' => $actualTotals['UP'],
                'total_bets_count' => (int) $bets->count(),
            ])->save();

            if (in_array($lockedRound->status, ['open', 'locked'], true) && now()->greaterThanOrEqualTo($lockedRound->ends_at)) {
                return $this->settleRound($lockedRound);
            }

            if ($lockedRound->status === 'settled' && in_array($lockedRound->winning_pot, self::POTS, true)) {
                if (! $lockedRound->dice_one || ! $lockedRound->dice_two || ! $lockedRound->dice_total) {
                    [$diceOne, $diceTwo] = $this->diceForPot($lockedRound->winning_pot);
                    $lockedRound->forceFill([
                        'dice_one' => $diceOne,
                        'dice_two' => $diceTwo,
                        'dice_total' => $diceOne + $diceTwo,
                    ])->save();
                }
                foreach ($lockedRound->bets as $bet) {
                    $isWinner = $bet->pot === $lockedRound->winning_pot;
                    $expectedPayout = $isWinner ? ((int) $bet->amount * (int) $bet->multiplier) : 0;
                    $bet->forceFill([
                        'status' => $isWinner ? 'won' : 'lost',
                        'payout_coins' => $expectedPayout,
                        'settled_at' => $bet->settled_at ?? now(),
                    ])->save();

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

    public function refundBet(SevenUpDownBet $bet, ?string $note = null): SevenUpDownBet
    {
        return DB::transaction(function () use ($bet, $note) {
            /** @var SevenUpDownBet $lockedBet */
            $lockedBet = SevenUpDownBet::query()
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
                'reference' => 'seven_up_down_bet:'.$lockedBet->id,
                'reference_type' => 'seven_up_down_bet',
                'reference_id' => $lockedBet->id,
                'description' => 'Lucky 7 bet refund',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'game' => 'seven_up_down',
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

            $this->financials->recordRefund($lockedBet);

            $remainingBets = SevenUpDownBet::query()
                ->where('seven_up_down_round_id', $lockedBet->seven_up_down_round_id)
                ->whereNull('refunded_at')
                ->get();
            $totals = $this->sumPotTotals($remainingBets);
            $lockedBet->round->forceFill([
                'total_bet_down' => $totals['DOWN'],
                'total_bet_seven' => $totals['SEVEN'],
                'total_bet_up' => $totals['UP'],
                'total_bets_count' => (int) $remainingBets->count(),
            ])->save();

            SevenUpDownBroadcaster::broadcast('seven_up_down:bet_refunded', [
                'round_id' => $lockedBet->seven_up_down_round_id,
                'round_key' => $lockedBet->round?->round_key,
                'bet_id' => $lockedBet->id,
                'user_id' => $lockedBet->user_id,
                'amount' => $refundCoins,
            ]);

            return $lockedBet->fresh(['user', 'round', 'walletTransaction', 'payout']);
        });
    }

    public function ensureCurrentRound(): SevenUpDownRound
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Lucky 7 is currently unavailable.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $round = SevenUpDownRound::query()->latest('id')->first();
            if (! $round) {
                return $this->createRound(CarbonImmutable::now());
            }

            $round = $this->refreshRoundState($round);

            if (in_array($round->status, ['open', 'locked'], true)) {
                return $round;
            }

            $displayUntil = $this->displayUntil($round);
            if (now()->greaterThanOrEqualTo($displayUntil)) {
                $nextStart = $displayUntil->lessThan(CarbonImmutable::now()) ? CarbonImmutable::now() : $displayUntil;

                return $this->createRound($nextStart);
            }

            return $round;
        }

        return SevenUpDownRound::query()->latest('id')->firstOrFail();
    }

    public function pruneIdleRounds(int $hours = 24): int
    {
        $cutoff = now()->subHours(max(1, $hours));

        return SevenUpDownRound::query()
            ->whereIn('status', ['settled', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->where('total_bets_count', 0)
            ->doesntHave('bets')
            ->doesntHave('payouts')
            ->delete();
    }

    private function resolveCurrentRound(bool $createIfIdle): ?SevenUpDownRound
    {
        if (! $this->enabled()) {
            throw new HttpException(403, 'Lucky 7 is currently unavailable.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $round = SevenUpDownRound::query()->latest('id')->first();
            if (! $round) {
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
                if (! $createIfIdle && ! $this->hasRecentActivityLease()) {
                    return $round;
                }

                $nextStart = $displayUntil->lessThan(CarbonImmutable::now()) ? CarbonImmutable::now() : $displayUntil;

                return $this->createRound($nextStart);
            }

            return $round;
        }

        return SevenUpDownRound::query()->latest('id')->first();
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

    public function refreshRoundState(SevenUpDownRound $round): SevenUpDownRound
    {
        $now = CarbonImmutable::now();

        if ($round->status === 'open' && $now->greaterThanOrEqualTo($round->locks_at)) {
            $round = DB::transaction(function () use ($round) {
                /** @var SevenUpDownRound $locked */
                $locked = SevenUpDownRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'open' && now()->greaterThanOrEqualTo($locked->locks_at)) {
                    $locked->forceFill(['status' => 'locked'])->save();
                    SevenUpDownBroadcaster::broadcast('seven_up_down:round_locked', [
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

    public function settleRound(SevenUpDownRound $round): SevenUpDownRound
    {
        return DB::transaction(function () use ($round) {
            /** @var SevenUpDownRound $lockedRound */
            $lockedRound = SevenUpDownRound::query()->with(['bets.user', 'bets.walletTransaction'])->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedRound->status, ['settled', 'cancelled'], true)) {
                return $lockedRound;
            }

            $bets = $lockedRound->bets;
            $winnerResult = $this->determineWinningPot($lockedRound, $bets);
            $winner = $winnerResult['pot'];
            $strategyMeta = $winnerResult['meta'];
            $multipliers = (array) data_get($lockedRound->meta, 'pot_multipliers', $this->potMultipliers());
            $winningMultiplier = $multipliers[$winner];
            [$diceOne, $diceTwo] = $this->diceForPot($winner);

            $lockedRound->forceFill([
                'winning_pot' => $winner,
                'winning_multiplier' => $winningMultiplier,
                'winning_strategy' => $this->winningStrategyMode(),
                'dice_one' => $diceOne,
                'dice_two' => $diceTwo,
                'dice_total' => $diceOne + $diceTwo,
            ])->save();

            foreach ($bets as $bet) {
                $isWinner = $bet->pot === $winner;
                $payoutCoins = $isWinner ? ((int) $bet->amount * (int) $bet->multiplier) : 0;

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
                'winning_multiplier' => $winningMultiplier,
                'winning_strategy' => $this->winningStrategyMode(),
                'dice_one' => $diceOne,
                'dice_two' => $diceTwo,
                'dice_total' => $diceOne + $diceTwo,
                'settled_at' => now(),
                'meta' => array_filter([
                    ...($lockedRound->meta ?? []),
                    'winning_decision' => $strategyMeta,
                ]),
            ])->save();

            $payload = $this->roundPayload($lockedRound->fresh());
            SevenUpDownBroadcaster::broadcast('seven_up_down:round_settled', [
                'round_id' => $lockedRound->id,
                'round_key' => $lockedRound->round_key,
                'snapshot' => $payload,
            ]);

            return $lockedRound->fresh();
        });
    }

    private function createRound(CarbonImmutable $startsAt): SevenUpDownRound
    {
        $lockAt = $startsAt->addSeconds(max(1, $this->roundDurationSeconds() - $this->bettingLockSeconds()));
        $endsAt = $startsAt->addSeconds($this->roundDurationSeconds());
        $displayUntil = $endsAt->addSeconds($this->resultDisplaySeconds());

        $round = SevenUpDownRound::query()->create([
            'round_key' => 'sud_'.Str::lower((string) Str::ulid()),
            'status' => 'open',
            'starts_at' => $startsAt,
            'locks_at' => $lockAt,
            'ends_at' => $endsAt,
            'meta' => [
                'round_duration_seconds' => $this->roundDurationSeconds(),
                'betting_lock_seconds' => $this->bettingLockSeconds(),
                'result_display_seconds' => $this->resultDisplaySeconds(),
                'display_until' => $displayUntil->toIso8601String(),
                'pot_multipliers' => $this->potMultipliers(),
                'outcome_weights' => $this->outcomeWeights(),
            ],
        ]);

        SevenUpDownBroadcaster::broadcast('seven_up_down:round_started', [
            'round_id' => $round->id,
            'round_key' => $round->round_key,
            'starts_at' => $round->starts_at?->toIso8601String(),
            'locks_at' => $round->locks_at?->toIso8601String(),
            'ends_at' => $round->ends_at?->toIso8601String(),
        ]);

        return $round;
    }

    private function determineWinningPot(SevenUpDownRound $round, Collection $bets): array
    {
        $totals = [
            'DOWN' => (int) $round->total_bet_down,
            'SEVEN' => (int) $round->total_bet_seven,
            'UP' => (int) $round->total_bet_up,
        ];
        $mode = $this->winningStrategyMode();
        if ($bets->isEmpty()) {
            return [
                'pot' => self::POTS[random_int(0, count(self::POTS) - 1)],
                'meta' => [
                    'mode' => $mode,
                    'reason' => 'empty_round_random',
                ],
            ];
        }

        $pot = match ($mode) {
            'minimum_bet' => collect($totals)->sort()->keys()->first(),
            'highest_bet' => collect($totals)->sortDesc()->keys()->first(),
            'treasury_affordable' => null,
            'probability' => $this->weightedPot(),
            default => self::POTS[random_int(0, count(self::POTS) - 1)],
        };

        if ($mode === 'treasury_affordable') {
            return $this->treasuryAffordableWinningPot(
                $totals,
                (array) data_get($round->meta, 'pot_multipliers', $this->potMultipliers()),
            );
        }

        return [
            'pot' => $pot,
            'meta' => [
                'mode' => $mode,
            ],
        ];
    }

    private function weightedPot(?array $allowedPots = null): string
    {
        $weights = $this->outcomeWeights();
        $allowedPots ??= self::POTS;
        $weights = collect($weights)->only($allowedPots)->all();
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));
        $cursor = 0;
        foreach ($allowedPots as $pot) {
            $cursor += $weights[$pot];
            if ($roll <= $cursor) {
                return $pot;
            }
        }

        return $allowedPots[0] ?? 'DOWN';
    }

    /** @return array{0:int,1:int} */
    private function diceForPot(string $pot): array
    {
        $pairs = [];
        for ($first = 1; $first <= 6; $first++) {
            for ($second = 1; $second <= 6; $second++) {
                $total = $first + $second;
                $matches = match ($pot) {
                    'DOWN' => $total < 7,
                    'SEVEN' => $total === 7,
                    'UP' => $total > 7,
                    default => false,
                };
                if ($matches) {
                    $pairs[] = [$first, $second];
                }
            }
        }

        return $pairs[random_int(0, count($pairs) - 1)];
    }

    private function treasuryAffordableWinningPot(array $totals, array $multipliers): array
    {
        $treasuryBalance = (int) $this->financials->account()->treasury_balance_coins;
        $liabilities = $this->potLiabilities($totals, $multipliers);
        $potsWithBets = collect($totals)->filter(fn (int $total) => $total > 0)->keys()->values()->all();
        $eligiblePots = collect($potsWithBets)
            ->filter(fn (string $pot) => $liabilities[$pot] < $treasuryBalance)
            ->values()
            ->all();

        $meta = [
            'mode' => 'treasury_affordable',
            'treasury_balance_before_settlement' => $treasuryBalance,
            'pot_multipliers' => $multipliers,
            'pot_totals' => $totals,
            'pot_payouts' => $liabilities,
            'eligible_pots' => $eligiblePots,
        ];

        if ($treasuryBalance <= 0) {
            return [
                'pot' => $this->minimumRealBetPot($totals),
                'meta' => [
                    ...$meta,
                    'reason' => 'treasury_recovery_minimum_bet',
                ],
            ];
        }

        if (count($potsWithBets) === 1) {
            $onlyPot = $potsWithBets[0];
            if (! in_array($onlyPot, $eligiblePots, true)) {
                return [
                    'pot' => $this->emptyPotFallback($totals),
                    'meta' => [
                        ...$meta,
                        'single_pot_roll' => null,
                        'reason' => 'single_pot_not_affordable',
                    ],
                ];
            }

            $roll = random_int(1, 100);
            if ($roll <= 75) {
                return [
                    'pot' => $onlyPot,
                    'meta' => [
                        ...$meta,
                        'single_pot_roll' => $roll,
                        'single_pot_win_probability_percent' => 75,
                    ],
                ];
            }

            return [
                'pot' => $this->emptyPotFallback($totals),
                'meta' => [
                    ...$meta,
                    'single_pot_roll' => $roll,
                    'single_pot_win_probability_percent' => 75,
                    'reason' => 'single_pot_probability_miss',
                ],
            ];
        }

        if ($eligiblePots === []) {
            if ($this->allPotsHaveBets($totals)) {
                return [
                    'pot' => $this->minimumRealBetPot($totals),
                    'meta' => [
                        ...$meta,
                        'reason' => 'treasury_overdraft_minimum_bet',
                    ],
                ];
            }

            return [
                'pot' => $this->emptyPotFallback($totals),
                'meta' => [
                    ...$meta,
                    'reason' => 'no_eligible_pot',
                ],
            ];
        }

        return [
            'pot' => $eligiblePots[random_int(0, count($eligiblePots) - 1)],
            'meta' => $meta,
        ];
    }

    private function minimumRealBetPot(array $totals): string
    {
        return (string) collect($totals)
            ->filter(fn (int $total) => $total > 0)
            ->sort()
            ->keys()
            ->first();
    }

    private function allPotsHaveBets(array $totals): bool
    {
        return collect($totals)->every(fn (int $total) => $total > 0);
    }

    private function emptyPotFallback(array $totals): ?string
    {
        $emptyPots = collect($totals)
            ->filter(fn (int $total) => $total === 0)
            ->keys()
            ->values()
            ->all();

        if ($emptyPots === []) {
            return null;
        }

        return $emptyPots[random_int(0, count($emptyPots) - 1)];
    }

    private function roundPayload(SevenUpDownRound $round, ?User $viewer = null): array
    {
        $round->loadMissing(['bets.user', 'payouts']);
        $now = CarbonImmutable::now();
        $phase = match (true) {
            $round->status === 'settled' => 'result',
            $round->status === 'cancelled' => 'cancelled',
            $now->greaterThanOrEqualTo($round->ends_at) => 'settling',
            $now->greaterThanOrEqualTo($round->locks_at) => 'locked',
            default => 'betting',
        };

        $viewerBets = $viewer
            ? $round->bets->where('user_id', $viewer->id)->values()->map(fn (SevenUpDownBet $bet) => $this->betPayload($bet))->all()
            : [];

        $countdownTarget = match ($phase) {
            'betting' => CarbonImmutable::parse($round->locks_at),
            'locked' => CarbonImmutable::parse($round->ends_at),
            default => $this->displayUntil($round),
        };
        $countdownSeconds = max(0, $now->diffInSeconds($countdownTarget, false));
        $realTotals = [
            'DOWN' => (int) $round->total_bet_down,
            'SEVEN' => (int) $round->total_bet_seven,
            'UP' => (int) $round->total_bet_up,
        ];
        $fakeTotals = $this->fakeBetsEnabled()
            ? $this->fakeTotalsForRound($round)
            : ['DOWN' => 0, 'SEVEN' => 0, 'UP' => 0];
        $displayTotals = [
            'DOWN' => $realTotals['DOWN'] + $fakeTotals['DOWN'],
            'SEVEN' => $realTotals['SEVEN'] + $fakeTotals['SEVEN'],
            'UP' => $realTotals['UP'] + $fakeTotals['UP'],
        ];
        $multipliers = (array) data_get($round->meta, 'pot_multipliers', $this->potMultipliers());
        $sectors = $this->outcomeWeights();

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
            'winning_multiplier' => $round->winning_multiplier ?: ($round->winning_pot ? $multipliers[$round->winning_pot] : null),
            'dice_one' => $round->dice_one ? (int) $round->dice_one : null,
            'dice_two' => $round->dice_two ? (int) $round->dice_two : null,
            'dice_total' => $round->dice_total ? (int) $round->dice_total : null,
            'countdown_seconds' => $countdownSeconds,
            'totals' => $displayTotals,
            'real_totals' => $realTotals,
            'fake_totals' => $fakeTotals,
            'pot_multipliers' => $multipliers,
            'outcome_weights' => $sectors,
            'total_bets_count' => (int) $round->total_bets_count,
            'participant_count' => (int) $round->bets->pluck('user_id')->unique()->count(),
            'viewer_bets' => $viewerBets,
        ];
    }

    private function displayUntil(SevenUpDownRound $round): CarbonImmutable
    {
        $displayUntil = data_get($round->meta, 'display_until');
        if (is_string($displayUntil) && trim($displayUntil) !== '') {
            return CarbonImmutable::parse($displayUntil);
        }

        return CarbonImmutable::parse($round->ends_at);
    }

    private function creditPayoutForBet(SevenUpDownRound $round, SevenUpDownBet $bet, string $winner): void
    {
        if (SevenUpDownPayout::query()->where('seven_up_down_bet_id', $bet->id)->exists()) {
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
            'reference' => 'seven_up_down_bet:'.$bet->id,
            'reference_type' => 'seven_up_down_bet',
            'reference_id' => $bet->id,
            'description' => "Lucky 7 payout for pot {$winner}",
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'meta' => [
                'game' => 'seven_up_down',
                'bet_id' => $bet->id,
                'round_key' => $round->round_key,
                'winning_pot' => $winner,
                'dice_one' => (int) $round->dice_one,
                'dice_two' => (int) $round->dice_two,
                'dice_total' => (int) $round->dice_total,
            ],
        ]);

        $payout = SevenUpDownPayout::query()->create([
            'seven_up_down_round_id' => $round->id,
            'seven_up_down_bet_id' => $bet->id,
            'user_id' => $user->id,
            'wallet_transaction_id' => $walletTx->id,
            'payout_coins' => $payoutCoins,
            'status' => 'credited',
            'settled_at' => now(),
            'meta' => [
                'winning_pot' => $winner,
                'winning_multiplier' => (int) $bet->multiplier,
                'dice_one' => (int) $round->dice_one,
                'dice_two' => (int) $round->dice_two,
                'dice_total' => (int) $round->dice_total,
            ],
        ]);

        $this->financials->recordPayout($payout);
    }

    private function betPayload(SevenUpDownBet $bet): array
    {
        return [
            'id' => $bet->id,
            'round_id' => $bet->seven_up_down_round_id,
            'user_id' => $bet->user_id,
            'pot' => $bet->pot,
            'amount' => (int) $bet->amount,
            'multiplier' => (int) $bet->multiplier,
            'status' => $bet->status,
            'payout_coins' => (int) $bet->payout_coins,
            'placed_at' => optional($bet->placed_at)->toIso8601String(),
            'settled_at' => optional($bet->settled_at)->toIso8601String(),
        ];
    }

    private function fakeTotalsForRound(SevenUpDownRound $round): array
    {
        $seed = sprintf('%s|%s', $round->round_key, $round->starts_at?->timestamp ?? 0);
        $band = max($this->minBet(), (int) floor(max($this->minBet(), $this->maxBet()) * 1.8));

        return [
            'DOWN' => $this->fakePotValue($seed.'|A', $band),
            'SEVEN' => $this->fakePotValue($seed.'|B', $band),
            'UP' => $this->fakePotValue($seed.'|C', $band),
        ];
    }

    private function fakePotValue(string $seed, int $band): int
    {
        $hash = abs(crc32($seed));
        $multiplier = 3 + ($hash % 10);
        $minBet = $this->minBet();
        $step = max($minBet, (int) floor($band / 7));
        $step = max($minBet, (int) (ceil($step / $minBet) * $minBet));

        return $multiplier * $step;
    }

    private function incrementRoundPot(SevenUpDownRound $round, string $pot, int $amount): void
    {
        $column = match ($pot) {
            'DOWN' => 'total_bet_down',
            'SEVEN' => 'total_bet_seven',
            default => 'total_bet_up',
        };

        $round->forceFill([
            $column => (int) $round->{$column} + $amount,
            'total_bets_count' => (int) $round->total_bets_count + 1,
        ])->save();
    }

    private function sumPotTotals(Collection $bets): array
    {
        return [
            'DOWN' => (int) $bets->where('pot', 'DOWN')->sum('amount'),
            'SEVEN' => (int) $bets->where('pot', 'SEVEN')->sum('amount'),
            'UP' => (int) $bets->where('pot', 'UP')->sum('amount'),
        ];
    }

    private function potLiabilities(array $totals, ?array $multipliers = null): array
    {
        $multipliers ??= $this->potMultipliers();

        return [
            'DOWN' => $totals['DOWN'] * $multipliers['DOWN'],
            'SEVEN' => $totals['SEVEN'] * $multipliers['SEVEN'],
            'UP' => $totals['UP'] * $multipliers['UP'],
        ];
    }

    private function adminFinancialAccountPayload(): array
    {
        if (! Schema::hasTable('seven_up_down_financial_accounts')) {
            return ['treasury_balance_coins' => 0, 'company_commission_balance_coins' => 0];
        }

        $account = SevenUpDownFinancialAccount::query()
            ->where('game_key', SevenUpDownFinancialService::GAME_KEY)
            ->first();

        return [
            'treasury_balance_coins' => (int) ($account?->treasury_balance_coins ?? 0),
            'company_commission_balance_coins' => (int) ($account?->company_commission_balance_coins ?? 0),
        ];
    }

    private function adminFinancialLedgerEntries(): Collection
    {
        if (! Schema::hasTable('seven_up_down_financial_ledger_entries')) {
            return collect();
        }

        return SevenUpDownFinancialLedgerEntry::query()
            ->with(['round', 'bet.user', 'payout'])
            ->latest('id')
            ->limit(12)
            ->get();
    }

    private function normalizeAdminReportWindow(array $filters): array
    {
        $period = strtolower(trim((string) ($filters['period'] ?? '7d')));
        $allowed = ['today', '7d', '30d', 'this_month', 'last_month', 'custom'];
        if (! in_array($period, $allowed, true)) {
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

        $betQuery = SevenUpDownBet::query();
        $this->applyAdminTimeWindow($betQuery, 'COALESCE(placed_at, created_at)', $window['start'], $window['end']);

        $payoutQuery = SevenUpDownPayout::query();
        $this->applyAdminTimeWindow($payoutQuery, 'COALESCE(settled_at, created_at)', $window['start'], $window['end']);

        $refundQuery = SevenUpDownBet::query()->whereNotNull('refunded_at');
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
