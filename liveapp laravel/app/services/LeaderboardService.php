<?php

namespace App\Services;

use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\LeaderboardDailyStat;
use App\Models\LiveRoomGiftEarningLedger;
use App\Models\User;
use App\Models\UserProfileFrame;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeaderboardService
{
    private const CACHE_TTL_SECONDS = 45;
    private const CACHE_VERSION_KEY = 'leaderboard:version';
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function recordGiftSuccess(
        int $senderUserId,
        int $hostId,
        ?int $agencyId,
        int $totalCoins,
        Carbon|string|null $occurredAt = null,
    ): void {
        if (!$this->leaderboardTableReady()) {
            return;
        }

        $date = $this->normalizeDate($occurredAt);
        $coins = max(0, $totalCoins);
        if ($coins === 0) {
            return;
        }

        $this->upsertIncrement('user', $senderUserId, $date, giftCoins: $coins);
        $this->upsertIncrement('host', $hostId, $date, giftCoins: $coins);

        if ($agencyId) {
            $this->upsertIncrement('agency', $agencyId, $date, giftCoins: $coins);
        }

        $this->bumpCacheVersion();
    }

    public function recordCallSuccess(
        int $callerUserId,
        int $hostId,
        ?int $agencyId,
        int $totalCoins,
        Carbon|string|null $occurredAt = null,
    ): void {
        if (!$this->leaderboardTableReady()) {
            return;
        }

        $date = $this->normalizeDate($occurredAt);
        $coins = max(0, $totalCoins);
        if ($coins === 0) {
            return;
        }

        $this->upsertIncrement('user', $callerUserId, $date, callCoins: $coins);
        $this->upsertIncrement('host', $hostId, $date, callCoins: $coins);

        if ($agencyId) {
            $this->upsertIncrement('agency', $agencyId, $date, callCoins: $coins);
        }

        $this->bumpCacheVersion();
    }

    public function recordSubscriptionPurchase(
        int $userId,
        int $totalCoins,
        Carbon|string|null $occurredAt = null,
    ): void {
        if (!$this->leaderboardTableReady()) {
            return;
        }

        $date = $this->normalizeDate($occurredAt);
        $coins = max(0, $totalCoins);
        if ($coins === 0) {
            return;
        }

        $this->upsertIncrement('user', $userId, $date, subscriptionCoins: $coins);
        $this->bumpCacheVersion();
    }

    public function recordEntryPurchase(
        int $userId,
        int $totalCoins,
        Carbon|string|null $occurredAt = null,
    ): void {
        if (!$this->leaderboardTableReady()) {
            return;
        }

        $date = $this->normalizeDate($occurredAt);
        $coins = max(0, $totalCoins);
        if ($coins === 0) {
            return;
        }

        $this->upsertIncrement('user', $userId, $date, entryCoins: $coins);
        $this->bumpCacheVersion();
    }

    public function payload(string $type = 'all', string $period = 'weekly', int $limit = 10): array
    {
        $type = strtolower(trim($type));
        $period = $this->normalizePeriodAlias($period);
        $limit = max(1, min(100, $limit));

        $usersAllTime = $this->topUsersAllTime($limit);
        if (!$this->leaderboardTableReady()) {
            return [
                'users_alltime' => $usersAllTime,
                'users_weekly' => [],
                'hosts_alltime' => [],
                'hosts_weekly' => [],
                'agencies_alltime' => [],
                'agencies_weekly' => [],
                'hosts' => [],
                'agencies' => [],
                'top_users_weekly' => [],
                'top_hosts_weekly' => [],
                'top_agencies_weekly' => [],
            ];
        }

        $usersWeekly = $this->topUsersWeekly($limit);
        $hostsWeekly = $this->topHosts('weekly', $limit);
        $hostsAllTime = $this->topHosts('alltime', $limit);
        $agenciesWeekly = $this->topAgencies('weekly', $limit);
        $agenciesAllTime = $this->topAgencies('alltime', $limit);
        $hosts = $period === 'alltime' ? $hostsAllTime : $hostsWeekly;
        $agencies = $period === 'alltime' ? $agenciesAllTime : $agenciesWeekly;

        if ($type === 'all') {
            return [
                'users_alltime' => $usersAllTime,
                'users_weekly' => $usersWeekly,
                'hosts_alltime' => $hostsAllTime,
                'hosts_weekly' => $hostsWeekly,
                'agencies_alltime' => $agenciesAllTime,
                'agencies_weekly' => $agenciesWeekly,
                'hosts' => $hostsWeekly,
                'agencies' => $agenciesWeekly,
                'top_users_weekly' => $usersWeekly,
                'top_hosts_weekly' => $hostsWeekly,
                'top_agencies_weekly' => $agenciesWeekly,
            ];
        }

        return match ($type) {
            'users' => [
                'users' => $period === 'alltime' ? $usersAllTime : $usersWeekly,
                'top_users_weekly' => $period === 'weekly' ? $usersWeekly : [],
            ],
            'hosts' => [
                'hosts' => $hosts,
                'hosts_alltime' => $hostsAllTime,
                'hosts_weekly' => $hostsWeekly,
                'top_hosts_weekly' => $hostsWeekly,
            ],
            'agencies' => [
                'agencies' => $agencies,
                'agencies_alltime' => $agenciesAllTime,
                'agencies_weekly' => $agenciesWeekly,
                'top_agencies_weekly' => $agenciesWeekly,
            ],
            default => [
                'users_alltime' => $usersAllTime,
                'users_weekly' => $usersWeekly,
                'hosts_alltime' => $hostsAllTime,
                'hosts_weekly' => $hostsWeekly,
                'agencies_alltime' => $agenciesAllTime,
                'agencies_weekly' => $agenciesWeekly,
                'hosts' => $hostsWeekly,
                'agencies' => $agenciesWeekly,
                'top_users_weekly' => $usersWeekly,
                'top_hosts_weekly' => $hostsWeekly,
                'top_agencies_weekly' => $agenciesWeekly,
            ],
        };
    }

    public function backfill(?string $from = null, ?string $to = null): array
    {
        if (!$this->leaderboardTableReady()) {
            return [
                'deleted_rows' => 0,
                'upserted_rows' => 0,
                'from' => $from,
                'to' => $to,
                'skipped' => 'leaderboard_daily_stats_missing',
            ];
        }

        $fromDate = $from ? Carbon::parse($from, self::BUSINESS_TIMEZONE)->startOfDay() : null;
        $toDate = $to ? Carbon::parse($to, self::BUSINESS_TIMEZONE)->endOfDay() : null;

        $deleteQuery = LeaderboardDailyStat::query();
        if ($fromDate) {
            $deleteQuery->whereDate('stat_date', '>=', $fromDate->toDateString());
        }
        if ($toDate) {
            $deleteQuery->whereDate('stat_date', '<=', $toDate->toDateString());
        }
        $deleted = $deleteQuery->delete();

        $giftBase = LiveRoomGiftEarningLedger::query();
        $callLedgerBase = CallEarningLedger::query();
        $callUserBase = CallSession::query()
            ->whereNotNull('caller_id')
            ->where('total_coins_charged', '>', 0);
        $subscriptionBase = WalletTransaction::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallet_transactions.type', 'debit')
            ->where('wallet_transactions.category', 'subscription')
            ->where('wallet_transactions.coins', '>', 0);
        $entryBase = WalletTransaction::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallet_transactions.type', 'debit')
            ->where('wallet_transactions.reference', 'like', 'ENTRY_PACK_PURCHASE:%')
            ->where('wallet_transactions.coins', '>', 0);

        if ($fromDate) {
            $giftBase->where('created_at', '>=', $fromDate);
            $callLedgerBase->where('created_at', '>=', $fromDate);
            $callUserBase->where('ended_at', '>=', $fromDate);
            $subscriptionBase->where('wallet_transactions.created_at', '>=', $fromDate);
            $entryBase->where('wallet_transactions.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $giftBase->where('created_at', '<=', $toDate);
            $callLedgerBase->where('created_at', '<=', $toDate);
            $callUserBase->where('ended_at', '<=', $toDate);
            $subscriptionBase->where('wallet_transactions.created_at', '<=', $toDate);
            $entryBase->where('wallet_transactions.created_at', '<=', $toDate);
        }

        $rows = [];

        foreach ($this->aggregateRollupRows(
            (clone $giftBase)->get(['sender_user_id', 'total_coins', 'created_at']),
            'sender_user_id',
            'created_at'
        ) as $row) {
            $rows[] = $this->rowPayload('user', $row['subject_id'], $row['stat_date'], giftCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $giftBase)->get(['host_id', 'total_coins', 'created_at']),
            'host_id',
            'created_at'
        ) as $row) {
            $rows[] = $this->rowPayload('host', $row['subject_id'], $row['stat_date'], giftCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $giftBase)->whereNotNull('agency_id')->get(['agency_id', 'total_coins', 'created_at']),
            'agency_id',
            'created_at'
        ) as $row) {
            $rows[] = $this->rowPayload('agency', $row['subject_id'], $row['stat_date'], giftCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $callUserBase)->get(['caller_id', 'total_coins_charged', 'ended_at', 'updated_at']),
            'caller_id',
            'ended_at',
            'total_coins_charged',
            'updated_at'
        ) as $row) {
            $rows[] = $this->rowPayload('user', $row['subject_id'], $row['stat_date'], callCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $callLedgerBase)->get(['host_id', 'total_coins', 'created_at']),
            'host_id',
            'created_at'
        ) as $row) {
            $rows[] = $this->rowPayload('host', $row['subject_id'], $row['stat_date'], callCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $callLedgerBase)->whereNotNull('agency_id')->get(['agency_id', 'total_coins', 'created_at']),
            'agency_id',
            'created_at'
        ) as $row) {
            $rows[] = $this->rowPayload('agency', $row['subject_id'], $row['stat_date'], callCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $subscriptionBase)->get(['wallets.user_id', 'wallet_transactions.coins', 'wallet_transactions.created_at']),
            'user_id',
            'created_at',
            'coins'
        ) as $row) {
            $rows[] = $this->rowPayload('user', $row['subject_id'], $row['stat_date'], subscriptionCoins: $row['coins']);
        }

        foreach ($this->aggregateRollupRows(
            (clone $entryBase)->get(['wallets.user_id', 'wallet_transactions.coins', 'wallet_transactions.created_at']),
            'user_id',
            'created_at',
            'coins'
        ) as $row) {
            $rows[] = $this->rowPayload('user', $row['subject_id'], $row['stat_date'], entryCoins: $row['coins']);
        }

        $mergedRows = $this->mergeRowPayloads($rows);

        if (!empty($mergedRows)) {
            LeaderboardDailyStat::query()->upsert(
                $mergedRows,
                ['subject_type', 'subject_id', 'stat_date'],
                [
                    'gift_coins',
                    'call_coins',
                    'subscription_coins',
                    'entry_coins',
                    'total_coins',
                    'updated_at',
                ]
            );
        }

        $this->bumpCacheVersion();

        return [
            'deleted_rows' => $deleted,
            'upserted_rows' => count($mergedRows),
            'from' => $fromDate?->toDateString(),
            'to' => $toDate?->toDateString(),
        ];
    }

    public function topUsersAllTime(int $limit = 10): array
    {
        return Cache::remember(
            $this->cacheKey('users', 'alltime', $limit),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            function () use ($limit): array {
                $rows = User::query()
                    ->leftJoin('user_levels', 'user_levels.id', '=', 'users.level_id')
                    ->select([
                        'users.id',
                        'users.name',
                        'users.avatar_url',
                        'users.lifetime_spend_coins',
                        'user_levels.level as level',
                    ])
                    ->orderByDesc('users.lifetime_spend_coins')
                    ->orderBy('users.id')
                    ->limit($limit)
                    ->get();

                $frameByUserId = $this->equippedFrameUrlsForUsers(
                    $rows->pluck('id')->map(fn ($id) => (int) $id)->all()
                );

                return $this->withRanks($rows, function ($row, int $rank) use ($frameByUserId): array {
                    return [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'avatar' => $row->avatar_url,
                        'profile_frame' => $frameByUserId[(int) $row->id] ?? null,
                        'level' => $row->level !== null ? (int) $row->level : null,
                        'lifetime_spend_coins' => (int) ($row->lifetime_spend_coins ?? 0),
                        'rank' => $rank,
                    ];
                });
            }
        );
    }

    public function topUsersWeekly(int $limit = 10): array
    {
        return Cache::remember(
            $this->cacheKey('users', 'weekly', $limit),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            function () use ($limit): array {
                [$weekStart, $weekEnd] = $this->weeklyRange();

                $rows = LeaderboardDailyStat::query()
                    ->join('users', 'users.id', '=', 'leaderboard_daily_stats.subject_id')
                    ->leftJoin('user_levels', 'user_levels.id', '=', 'users.level_id')
                    ->where('leaderboard_daily_stats.subject_type', 'user')
                    ->whereBetween('leaderboard_daily_stats.stat_date', [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ])
                    ->groupBy('users.id', 'users.name', 'users.avatar_url', 'user_levels.level', 'users.lifetime_spend_coins')
                    ->selectRaw('
                        users.id as id,
                        users.name as name,
                        users.avatar_url as avatar_url,
                        user_levels.level as level,
                        users.lifetime_spend_coins as lifetime_spend_coins,
                        SUM(leaderboard_daily_stats.gift_coins) as gift_coins,
                        SUM(leaderboard_daily_stats.call_coins) as call_coins,
                        SUM(leaderboard_daily_stats.subscription_coins) as subscription_coins,
                        SUM(leaderboard_daily_stats.entry_coins) as entry_coins,
                        SUM(leaderboard_daily_stats.total_coins) as total_coins
                    ')
                    ->orderByDesc('total_coins')
                    ->orderBy('users.id')
                    ->limit($limit)
                    ->get();

                $frameByUserId = $this->equippedFrameUrlsForUsers(
                    $rows->pluck('id')->map(fn ($id) => (int) $id)->all()
                );

                return $this->withRanks($rows, function ($row, int $rank) use ($frameByUserId): array {
                    return [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'avatar' => $row->avatar_url,
                        'profile_frame' => $frameByUserId[(int) $row->id] ?? null,
                        'level' => $row->level !== null ? (int) $row->level : null,
                        'gift_coins' => (int) ($row->gift_coins ?? 0),
                        'call_coins' => (int) ($row->call_coins ?? 0),
                        'subscription_coins' => (int) ($row->subscription_coins ?? 0),
                        'entry_coins' => (int) ($row->entry_coins ?? 0),
                        'total_coins' => (int) ($row->total_coins ?? 0),
                        'lifetime_spend_coins' => (int) ($row->lifetime_spend_coins ?? 0),
                        'rank' => $rank,
                    ];
                });
            }
        );
    }

    public function topHosts(string $period = 'weekly', int $limit = 10): array
    {
        $period = $this->normalizePeriodAlias($period);

        return Cache::remember(
            $this->cacheKey('hosts', $period, $limit),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            function () use ($period, $limit): array {
                $query = LeaderboardDailyStat::query()
                    ->join('hosts', 'hosts.id', '=', 'leaderboard_daily_stats.subject_id')
                    ->join('users', 'users.id', '=', 'hosts.user_id')
                    ->where('leaderboard_daily_stats.subject_type', 'host');

                if ($period === 'weekly') {
                    [$weekStart, $weekEnd] = $this->weeklyRange();
                    $query->whereBetween('leaderboard_daily_stats.stat_date', [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ]);
                }

                $rows = $query
                    ->groupBy('hosts.id', 'hosts.user_id', 'hosts.agency_id', 'hosts.stage_name', 'users.name', 'users.avatar_url')
                    ->selectRaw('
                        hosts.id as host_id,
                        hosts.user_id as host_user_id,
                        hosts.agency_id as agency_id,
                        COALESCE(NULLIF(hosts.stage_name, \'\'), users.name) as display_name,
                        users.avatar_url as avatar_url,
                        SUM(leaderboard_daily_stats.gift_coins) as gift_coins,
                        SUM(leaderboard_daily_stats.call_coins) as call_coins,
                        SUM(leaderboard_daily_stats.total_coins) as total_coins
                    ')
                    ->orderByDesc('total_coins')
                    ->orderBy('hosts.id')
                    ->limit($limit)
                    ->get();

                $frameByUserId = $this->equippedFrameUrlsForUsers(
                    $rows->pluck('host_user_id')->map(fn ($id) => (int) $id)->all()
                );

                return $this->withRanks($rows, function ($row, int $rank) use ($frameByUserId): array {
                    return [
                        'host_id' => (int) $row->host_id,
                        'host_user_id' => (int) $row->host_user_id,
                        'name' => (string) $row->display_name,
                        'avatar' => $row->avatar_url,
                        'profile_frame' => $frameByUserId[(int) $row->host_user_id] ?? null,
                        'agency_id' => $row->agency_id !== null ? (int) $row->agency_id : null,
                        'gift_coins' => (int) ($row->gift_coins ?? 0),
                        'call_coins' => (int) ($row->call_coins ?? 0),
                        'total_coins' => (int) ($row->total_coins ?? 0),
                        'rank' => $rank,
                    ];
                });
            }
        );
    }

    public function topAgencies(string $period = 'weekly', int $limit = 10): array
    {
        $period = $this->normalizePeriodAlias($period);

        return Cache::remember(
            $this->cacheKey('agencies', $period, $limit),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            function () use ($period, $limit): array {
                $query = LeaderboardDailyStat::query()
                    ->join('agencies', 'agencies.id', '=', 'leaderboard_daily_stats.subject_id')
                    ->where('leaderboard_daily_stats.subject_type', 'agency');

                if ($period === 'weekly') {
                    [$weekStart, $weekEnd] = $this->weeklyRange();
                    $query->whereBetween('leaderboard_daily_stats.stat_date', [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ]);
                }

                $rows = $query
                    ->groupBy('agencies.id', 'agencies.name')
                    ->selectRaw('
                        agencies.id as agency_id,
                        agencies.name as name,
                        SUM(leaderboard_daily_stats.gift_coins) as gift_coins,
                        SUM(leaderboard_daily_stats.call_coins) as call_coins,
                        SUM(leaderboard_daily_stats.total_coins) as total_coins
                    ')
                    ->orderByDesc('total_coins')
                    ->orderBy('agencies.id')
                    ->limit($limit)
                    ->get();

                return $this->withRanks($rows, function ($row, int $rank): array {
                    return [
                        'agency_id' => (int) $row->agency_id,
                        'name' => (string) $row->name,
                        'gift_coins' => (int) ($row->gift_coins ?? 0),
                        'call_coins' => (int) ($row->call_coins ?? 0),
                        'total_coins' => (int) ($row->total_coins ?? 0),
                        'rank' => $rank,
                    ];
                });
            }
        );
    }

    private function upsertIncrement(
        string $subjectType,
        int $subjectId,
        Carbon $statDate,
        int $giftCoins = 0,
        int $callCoins = 0,
        int $subscriptionCoins = 0,
        int $entryCoins = 0,
    ): void {
        if ($subjectId <= 0) {
            return;
        }

        $totalCoins = $giftCoins + $callCoins + $subscriptionCoins + $entryCoins;
        if ($totalCoins <= 0) {
            return;
        }

        $timestamp = now()->toDateTimeString();

        DB::statement(
            '
            INSERT INTO leaderboard_daily_stats
                (subject_type, subject_id, stat_date, gift_coins, call_coins, subscription_coins, entry_coins, total_coins, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                gift_coins = gift_coins + VALUES(gift_coins),
                call_coins = call_coins + VALUES(call_coins),
                subscription_coins = subscription_coins + VALUES(subscription_coins),
                entry_coins = entry_coins + VALUES(entry_coins),
                total_coins = total_coins + VALUES(total_coins),
                updated_at = VALUES(updated_at)
            ',
            [
                $subjectType,
                $subjectId,
                $statDate->toDateString(),
                $giftCoins,
                $callCoins,
                $subscriptionCoins,
                $entryCoins,
                $totalCoins,
                $timestamp,
                $timestamp,
            ]
        );
    }

    private function cacheKey(string $type, string $period, int $limit): string
    {
        return sprintf(
            'leaderboard:%s:%s:v%s:%d',
            $type,
            $period,
            $this->cacheVersion(),
            $limit
        );
    }

    private function cacheVersion(): int
    {
        if (!Cache::has(self::CACHE_VERSION_KEY)) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
        }

        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    private function bumpCacheVersion(): void
    {
        if (!Cache::has(self::CACHE_VERSION_KEY)) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
        }

        Cache::increment(self::CACHE_VERSION_KEY);
    }

    private function normalizeDate(Carbon|string|null $occurredAt): Carbon
    {
        $source = $occurredAt instanceof Carbon
            ? $occurredAt->copy()
            : (is_string($occurredAt) && trim($occurredAt) !== ''
                ? Carbon::parse($occurredAt)
                : now());

        return $source
            ->copy()
            ->setTimezone(self::BUSINESS_TIMEZONE)
            ->startOfDay();
    }

    private function weeklyRange(): array
    {
        $weekStart = now(self::BUSINESS_TIMEZONE)->startOfWeek(Carbon::MONDAY);
        $weekEnd = now(self::BUSINESS_TIMEZONE)->endOfWeek(Carbon::SUNDAY);

        return [$weekStart, $weekEnd];
    }

    private function normalizePeriodAlias(string $period): string
    {
        return strtolower(trim($period)) === 'alltime' ? 'alltime' : 'weekly';
    }

    private function withRanks(Collection $rows, callable $map): array
    {
        $rank = 0;

        return $rows->map(function ($row) use (&$rank, $map) {
            $rank++;
            return $map($row, $rank);
        })->values()->all();
    }

    private function rowPayload(
        string $subjectType,
        int $subjectId,
        string $statDate,
        int $giftCoins = 0,
        int $callCoins = 0,
        int $subscriptionCoins = 0,
        int $entryCoins = 0,
    ): array {
        $timestamp = now()->toDateTimeString();

        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'stat_date' => $statDate,
            'gift_coins' => $giftCoins,
            'call_coins' => $callCoins,
            'subscription_coins' => $subscriptionCoins,
            'entry_coins' => $entryCoins,
            'total_coins' => $giftCoins + $callCoins + $subscriptionCoins + $entryCoins,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function aggregateRollupRows(
        Collection $records,
        string $subjectField,
        string $dateField,
        string $coinField = 'total_coins',
        ?string $fallbackDateField = null,
    ): array {
        $bucketed = [];

        foreach ($records as $record) {
            $subjectId = (int) data_get($record, $subjectField);
            if ($subjectId <= 0) {
                continue;
            }

            $rawDate = data_get($record, $dateField) ?: ($fallbackDateField ? data_get($record, $fallbackDateField) : null);
            if (!$rawDate) {
                continue;
            }

            $statDate = $this->normalizeDate($rawDate)->toDateString();
            $coins = (int) data_get($record, $coinField, 0);
            if ($coins <= 0) {
                continue;
            }

            $key = $subjectId.'|'.$statDate;

            if (!isset($bucketed[$key])) {
                $bucketed[$key] = [
                    'subject_id' => $subjectId,
                    'stat_date' => $statDate,
                    'coins' => 0,
                ];
            }

            $bucketed[$key]['coins'] += $coins;
        }

        return array_values($bucketed);
    }

    private function mergeRowPayloads(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                $row['subject_type'],
                $row['subject_id'],
                $row['stat_date'],
            ]);

            if (!isset($merged[$key])) {
                $merged[$key] = $row;
                continue;
            }

            $merged[$key]['gift_coins'] += (int) ($row['gift_coins'] ?? 0);
            $merged[$key]['call_coins'] += (int) ($row['call_coins'] ?? 0);
            $merged[$key]['subscription_coins'] += (int) ($row['subscription_coins'] ?? 0);
            $merged[$key]['entry_coins'] += (int) ($row['entry_coins'] ?? 0);
            $merged[$key]['total_coins'] += (int) ($row['total_coins'] ?? 0);
            $merged[$key]['updated_at'] = $row['updated_at'];
        }

        return array_values($merged);
    }

    private function equippedFrameUrlsForUsers(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        return UserProfileFrame::query()
            ->with('profileFrame')
            ->whereIn('user_id', $userIds)
            ->where('is_equipped', true)
            ->orderByDesc('id')
            ->get()
            ->filter(function (UserProfileFrame $ownership): bool {
                if ($ownership->expires_at !== null && $ownership->expires_at->isPast()) {
                    return false;
                }

                return (bool) $ownership->profileFrame?->is_active;
            })
            ->unique('user_id')
            ->mapWithKeys(fn (UserProfileFrame $ownership) => [
                (int) $ownership->user_id => $ownership->profileFrame?->asset_url,
            ])
            ->all();
    }

    private function leaderboardTableReady(): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        if (!Schema::hasTable('leaderboard_daily_stats')) {
            return $ready = false;
        }

        foreach ([
            'subject_type',
            'subject_id',
            'stat_date',
            'gift_coins',
            'call_coins',
            'total_coins',
        ] as $column) {
            if (!Schema::hasColumn('leaderboard_daily_stats', $column)) {
                return $ready = false;
            }
        }

        return $ready = true;
    }
}
