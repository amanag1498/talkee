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
        ],
        [
            'source' => 'weekly_top_agency',
            'slug' => 'silver-sapphire-crown',
            'title' => 'Last Week Top Agency',
            'leaderboard' => 'weekly_agency',
        ],
        [
            'source' => 'weekly_top_host',
            'slug' => 'scarlet-regal-crown',
            'title' => 'Last Week Top Host',
            'leaderboard' => 'weekly_host',
        ],
        [
            'source' => 'alltime_top_gifter',
            'slug' => 'silver-amethyst-crown',
            'title' => 'All Time Top Gifter',
            'leaderboard' => 'alltime_user',
        ],
        [
            'source' => 'alltime_top_agency',
            'slug' => 'ivory-laurel-crown',
            'title' => 'All Time Top Agency',
            'leaderboard' => 'alltime_agency',
        ],
        [
            'source' => 'alltime_top_host',
            'slug' => 'obsidian-crown-laurel',
            'title' => 'All Time Top Host',
            'leaderboard' => 'alltime_host',
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
            $winner = $this->resolveWinner($definition['leaderboard'], $weekStart, $weekEnd);
            if ($winner === null) {
                $rows[] = [
                    'source' => $definition['source'],
                    'title' => $definition['title'],
                    'frame_slug' => $definition['slug'],
                    'status' => 'no_winner',
                    'winner_name' => null,
                    'user_id' => null,
                    'expires_at' => null,
                ];
                $skipped++;
                continue;
            }

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
                    '%s unlocked for 7 days after your %s finish.',
                    $ownership->profileFrame?->name ?? 'Profile frame',
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
                'expires_at' => $ownership->expires_at?->toIso8601String(),
            ];
            $granted++;
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

    private function resolveWinner(string $leaderboard, Carbon $weekStart, Carbon $weekEnd): ?array
    {
        return match ($leaderboard) {
            'weekly_user' => $this->weeklyTopUser($weekStart, $weekEnd),
            'weekly_host' => $this->weeklyTopHost($weekStart, $weekEnd),
            'weekly_agency' => $this->weeklyTopAgency($weekStart, $weekEnd),
            'alltime_user' => $this->allTimeTopUser(),
            'alltime_host' => $this->allTimeTopHost(),
            'alltime_agency' => $this->allTimeTopAgency(),
            default => null,
        };
    }

    private function weeklyTopUser(Carbon $weekStart, Carbon $weekEnd): ?array
    {
        if (!$this->hasLeaderboardRollups()) {
            return null;
        }

        $row = LeaderboardDailyStat::query()
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
            ->first();

        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);
        if (!$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) $row->name,
        ];
    }

    private function weeklyTopHost(Carbon $weekStart, Carbon $weekEnd): ?array
    {
        if (!$this->hasLeaderboardRollups()) {
            return null;
        }

        $row = LeaderboardDailyStat::query()
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
            ->first();

        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);
        if (!$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) $row->display_name,
        ];
    }

    private function weeklyTopAgency(Carbon $weekStart, Carbon $weekEnd): ?array
    {
        if (!$this->hasLeaderboardRollups()) {
            return null;
        }

        $row = LeaderboardDailyStat::query()
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
            ->first();

        if (!$row) {
            return null;
        }

        $agency = Agency::query()->find((int) $row->agency_id);
        $user = $agency?->owner;
        if (!$agency || !$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) $row->name,
        ];
    }

    private function allTimeTopUser(): ?array
    {
        $row = $this->leaderboards->topUsersAllTime(1)[0] ?? null;
        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) ($row['id'] ?? 0));
        if (!$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) ($row['name'] ?? $user->name),
        ];
    }

    private function allTimeTopHost(): ?array
    {
        if (!$this->hasLeaderboardRollups()) {
            return null;
        }

        $row = $this->leaderboards->topHosts('alltime', 1)[0] ?? null;
        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) ($row['host_user_id'] ?? 0));
        if (!$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) ($row['name'] ?? $user->name),
        ];
    }

    private function allTimeTopAgency(): ?array
    {
        if (!$this->hasLeaderboardRollups()) {
            return null;
        }

        $row = $this->leaderboards->topAgencies('alltime', 1)[0] ?? null;
        if (!$row) {
            return null;
        }

        $agency = Agency::query()->find((int) ($row['agency_id'] ?? 0));
        $user = $agency?->owner;
        if (!$agency || !$user) {
            return null;
        }

        return [
            'user' => $user,
            'name' => (string) ($row['name'] ?? $agency->name),
        ];
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
