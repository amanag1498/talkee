<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\LeaderboardDailyStat;
use App\Models\User;
use App\Models\UserProfileFrame;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ProfileFrameAwardService
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';
    private const REWARD_DAYS = 7;

    private const AWARDS = [
        [
            'source' => 'weekly_top_gifter',
            'slug' => 'blush-laurel-crown',
            'title' => 'Last Week Top Gifter',
            'leaderboard' => 'weekly_user',
            'top_n' => 3,
        ],
        [
            'source' => 'weekly_top_agency',
            'slug' => 'silver-sapphire-crown',
            'title' => 'Last Week Top Agency',
            'leaderboard' => 'weekly_agency',
            'top_n' => 3,
        ],
        [
            'source' => 'weekly_top_host',
            'slug' => 'scarlet-regal-crown',
            'title' => 'Last Week Top Host',
            'leaderboard' => 'weekly_host',
            'top_n' => 3,
        ],
        [
            'source' => 'alltime_top_gifter',
            'slug' => 'silver-amethyst-crown',
            'title' => 'All Time Top Gifter',
            'leaderboard' => 'alltime_user',
            'top_n' => 3,
        ],
        [
            'source' => 'alltime_top_agency',
            'slug' => 'ivory-laurel-crown',
            'title' => 'All Time Top Agency',
            'leaderboard' => 'alltime_agency',
            'top_n' => 3,
        ],
        [
            'source' => 'alltime_top_host',
            'slug' => 'obsidian-crown-laurel',
            'title' => 'All Time Top Host',
            'leaderboard' => 'alltime_host',
            'top_n' => 3,
        ],
    ];

    public function __construct(
        private readonly LeaderboardService $leaderboards,
        private readonly ProfileFrameService $frames,
    ) {
    }

    public function runSevenDayAwards(?Carbon $runAt = null): array
    {
        $runAt = ($runAt ?: now(self::BUSINESS_TIMEZONE))->copy()->setTimezone(self::BUSINESS_TIMEZONE);
        $expiresAt = $runAt->copy()->addDays(self::REWARD_DAYS);
        [$weekStart, $weekEnd] = $this->previousWeekRange($runAt);

        $rows = [];
        $granted = 0;
        $skipped = 0;

        foreach (self::AWARDS as $definition) {
            $winners = $this->resolveWinners(
                $definition['leaderboard'],
                $weekStart,
                $weekEnd,
                (int) ($definition['top_n'] ?? 1),
            );

            if ($winners === []) {
                $rows[] = [
                    'source' => $definition['source'],
                    'title' => $definition['title'],
                    'frame_slug' => $definition['slug'],
                    'status' => 'no_winner',
                    'winner_name' => null,
                    'user_id' => null,
                    'rank' => null,
                    'expires_at' => null,
                ];
                $skipped++;
                continue;
            }

            foreach ($winners as $winner) {
                $currentOwnership = UserProfileFrame::query()
                    ->where('user_id', $winner['user']->id)
                    ->whereHas('profileFrame', fn ($query) => $query->where('slug', $definition['slug']))
                    ->first();

                $alreadyCovered = $currentOwnership !== null
                    && $currentOwnership->expires_at !== null
                    && $currentOwnership->expires_at->greaterThanOrEqualTo($expiresAt);

                if ($alreadyCovered) {
                    $rows[] = [
                        'source' => $definition['source'],
                        'title' => $definition['title'],
                        'frame_slug' => $definition['slug'],
                        'status' => 'already_active',
                        'winner_name' => $winner['name'],
                        'user_id' => $winner['user']->id,
                        'rank' => $winner['rank'],
                        'expires_at' => $currentOwnership->expires_at?->toIso8601String(),
                    ];
                    $skipped++;
                    continue;
                }

                $ownership = $this->frames->grantBySlug(
                    $winner['user'],
                    $definition['slug'],
                    $definition['source'],
                    $expiresAt,
                    true,
                );

                $this->frames->notifyUnlocked(
                    $winner['user'],
                    $ownership,
                    $definition['title'].' frame unlocked',
                    sprintf(
                        '%s unlocked for 7 days after your rank #%d %s finish.',
                        $ownership->profileFrame?->name ?? 'Profile frame',
                        (int) $winner['rank'],
                        strtolower($definition['title']),
                    ),
                    false,
                );

                $rows[] = [
                    'source' => $definition['source'],
                    'title' => $definition['title'],
                    'frame_slug' => $definition['slug'],
                    'status' => 'granted',
                    'winner_name' => $winner['name'],
                    'user_id' => $winner['user']->id,
                    'rank' => $winner['rank'],
                    'expires_at' => $ownership->expires_at?->toIso8601String(),
                ];
                $granted++;
            }
        }

        return [
            'ran_at' => $runAt->toIso8601String(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'reward_days' => self::REWARD_DAYS,
            'granted_count' => $granted,
            'skipped_count' => $skipped,
            'rows' => $rows,
        ];
    }

    private function resolveWinners(string $leaderboard, Carbon $weekStart, Carbon $weekEnd, int $limit): array
    {
        $limit = max(1, $limit);

        return match ($leaderboard) {
            'weekly_user' => $this->weeklyTopUsers($weekStart, $weekEnd, $limit),
            'weekly_host' => $this->weeklyTopHosts($weekStart, $weekEnd, $limit),
            'weekly_agency' => $this->weeklyTopAgencies($weekStart, $weekEnd, $limit),
            'alltime_user' => $this->allTimeTopUsers($limit),
            'alltime_host' => $this->allTimeTopHosts($limit),
            'alltime_agency' => $this->allTimeTopAgencies($limit),
            default => [],
        };
    }

    private function weeklyTopUsers(Carbon $weekStart, Carbon $weekEnd, int $limit): array
    {
        if (!$this->hasLeaderboardRollups()) {
            return [];
        }

        $rows = LeaderboardDailyStat::query()
            ->join('users', 'users.id', '=', 'leaderboard_daily_stats.subject_id')
            ->where('leaderboard_daily_stats.subject_type', 'user')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            ])
            ->groupBy('users.id', 'users.name')
            ->selectRaw('users.id as user_id, users.name as name, SUM(leaderboard_daily_stats.total_coins) as total_coins')
            ->orderByDesc('total_coins')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        return $this->mapRankedUsers($rows, 'user_id', 'name');
    }

    private function weeklyTopHosts(Carbon $weekStart, Carbon $weekEnd, int $limit): array
    {
        if (!$this->hasLeaderboardRollups()) {
            return [];
        }

        $rows = LeaderboardDailyStat::query()
            ->join('hosts', 'hosts.id', '=', 'leaderboard_daily_stats.subject_id')
            ->join('users', 'users.id', '=', 'hosts.user_id')
            ->where('leaderboard_daily_stats.subject_type', 'host')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            ])
            ->groupBy('hosts.id', 'hosts.user_id', 'hosts.stage_name', 'users.name')
            ->selectRaw("
                hosts.id as host_id,
                hosts.user_id as user_id,
                COALESCE(NULLIF(hosts.stage_name, ''), users.name) as display_name,
                SUM(leaderboard_daily_stats.total_coins) as total_coins
            ")
            ->orderByDesc('total_coins')
            ->orderBy('hosts.id')
            ->limit($limit)
            ->get();

        return $this->mapRankedUsers($rows, 'user_id', 'display_name');
    }

    private function weeklyTopAgencies(Carbon $weekStart, Carbon $weekEnd, int $limit): array
    {
        if (!$this->hasLeaderboardRollups()) {
            return [];
        }

        $rows = LeaderboardDailyStat::query()
            ->join('agencies', 'agencies.id', '=', 'leaderboard_daily_stats.subject_id')
            ->where('leaderboard_daily_stats.subject_type', 'agency')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $weekStart->toDateString(),
                $weekEnd->toDateString(),
            ])
            ->groupBy('agencies.id', 'agencies.name')
            ->selectRaw('agencies.id as agency_id, agencies.name as name, SUM(leaderboard_daily_stats.total_coins) as total_coins')
            ->orderByDesc('total_coins')
            ->orderBy('agencies.id')
            ->limit($limit)
            ->get();

        return $this->mapRankedAgencies($rows);
    }

    private function allTimeTopUsers(int $limit): array
    {
        return $this->mapRankedLeaderboardUsers(
            $this->leaderboards->topUsersAllTime($limit),
            'id',
            'name',
        );
    }

    private function allTimeTopHosts(int $limit): array
    {
        if (!$this->hasLeaderboardRollups()) {
            return [];
        }

        return $this->mapRankedLeaderboardUsers(
            $this->leaderboards->topHosts('alltime', $limit),
            'host_user_id',
            'name',
        );
    }

    private function allTimeTopAgencies(int $limit): array
    {
        if (!$this->hasLeaderboardRollups()) {
            return [];
        }

        return $this->mapRankedLeaderboardAgencies(
            $this->leaderboards->topAgencies('alltime', $limit),
        );
    }

    private function mapRankedUsers($rows, string $userIdField, string $nameField): array
    {
        $rank = 0;

        return $rows->map(function ($row) use (&$rank, $userIdField, $nameField): ?array {
            $user = User::query()->find((int) data_get($row, $userIdField));
            if (!$user) {
                return null;
            }

            $rank++;

            return [
                'user' => $user,
                'name' => (string) data_get($row, $nameField, $user->name),
                'rank' => $rank,
            ];
        })->filter()->values()->all();
    }

    private function mapRankedAgencies($rows): array
    {
        $rank = 0;

        return $rows->map(function ($row) use (&$rank): ?array {
            $agency = Agency::query()->find((int) data_get($row, 'agency_id'));
            $user = $agency?->owner;
            if (!$agency || !$user) {
                return null;
            }

            $rank++;

            return [
                'user' => $user,
                'name' => (string) data_get($row, 'name', $agency->name),
                'rank' => $rank,
            ];
        })->filter()->values()->all();
    }

    private function mapRankedLeaderboardUsers(array $rows, string $userIdField, string $nameField): array
    {
        $rank = 0;

        return collect($rows)->map(function (array $row) use (&$rank, $userIdField, $nameField): ?array {
            $user = User::query()->find((int) ($row[$userIdField] ?? 0));
            if (!$user) {
                return null;
            }

            $rank++;

            return [
                'user' => $user,
                'name' => (string) ($row[$nameField] ?? $user->name),
                'rank' => $rank,
            ];
        })->filter()->values()->all();
    }

    private function mapRankedLeaderboardAgencies(array $rows): array
    {
        $rank = 0;

        return collect($rows)->map(function (array $row) use (&$rank): ?array {
            $agency = Agency::query()->find((int) ($row['agency_id'] ?? 0));
            $user = $agency?->owner;
            if (!$agency || !$user) {
                return null;
            }

            $rank++;

            return [
                'user' => $user,
                'name' => (string) ($row['name'] ?? $agency->name),
                'rank' => $rank,
            ];
        })->filter()->values()->all();
    }

    private function previousWeekRange(Carbon $reference): array
    {
        $start = $reference->copy()
            ->subWeek()
            ->startOfWeek(Carbon::MONDAY)
            ->startOfDay();

        return [$start, $start->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()];
    }

    private function hasLeaderboardRollups(): bool
    {
        return Schema::hasTable('leaderboard_daily_stats');
    }
}
