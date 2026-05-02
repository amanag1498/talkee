<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaderboardDailyStat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaderboardReportController extends Controller
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function index(Request $request)
    {
        [$fromDate, $toDate, $selectedWeek] = $this->resolveDateRange($request);
        $limit = max(1, min(200, (int) $request->input('limit', 25)));

        $users = $this->weeklyUsers($fromDate, $toDate, (string) $request->input('user_q', ''), $limit);
        $hosts = $this->weeklyHosts($fromDate, $toDate, (string) $request->input('host_q', ''), $limit);
        $agencies = $this->weeklyAgencies($fromDate, $toDate, (string) $request->input('agency_q', ''), $limit);

        return view('admin.reports.leaderboards', [
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'selectedWeek' => $selectedWeek,
            'limit' => $limit,
            'userQuery' => (string) $request->input('user_q', ''),
            'hostQuery' => (string) $request->input('host_q', ''),
            'agencyQuery' => (string) $request->input('agency_q', ''),
            'users' => $users,
            'hosts' => $hosts,
            'agencies' => $agencies,
            'rangeLabel' => $this->rangeLabel($fromDate, $toDate),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$fromDate, $toDate] = $this->resolveDateRange($request);
        $limit = max(1, min(500, (int) $request->input('limit', 100)));
        $dataset = strtolower((string) $request->input('dataset', 'users'));

        $rows = match ($dataset) {
            'hosts' => $this->weeklyHosts($fromDate, $toDate, (string) $request->input('host_q', ''), $limit),
            'agencies' => $this->weeklyAgencies($fromDate, $toDate, (string) $request->input('agency_q', ''), $limit),
            default => $this->weeklyUsers($fromDate, $toDate, (string) $request->input('user_q', ''), $limit),
        };

        $filename = sprintf(
            'leaderboard-%s-%s-to-%s.csv',
            $dataset,
            $fromDate->format('Ymd'),
            $toDate->format('Ymd'),
        );

        return response()->stream(function () use ($dataset, $rows) {
            $out = fopen('php://output', 'w');

            if ($dataset === 'hosts') {
                fputcsv($out, ['rank', 'host_id', 'host_user_id', 'name', 'agency_name', 'gift_coins', 'call_coins', 'total_coins']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['rank'],
                        $row['host_id'],
                        $row['host_user_id'],
                        $row['name'],
                        $row['agency_name'],
                        $row['gift_coins'],
                        $row['call_coins'],
                        $row['total_coins'],
                    ]);
                }
            } elseif ($dataset === 'agencies') {
                fputcsv($out, ['rank', 'agency_id', 'name', 'host_count', 'gift_coins', 'call_coins', 'total_coins']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['rank'],
                        $row['agency_id'],
                        $row['name'],
                        $row['host_count'],
                        $row['gift_coins'],
                        $row['call_coins'],
                        $row['total_coins'],
                    ]);
                }
            } else {
                fputcsv($out, ['rank', 'user_id', 'name', 'email', 'level', 'gift_coins', 'call_coins', 'subscription_coins', 'entry_coins', 'total_coins']);
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['rank'],
                        $row['id'],
                        $row['name'],
                        $row['email'],
                        $row['level'],
                        $row['gift_coins'],
                        $row['call_coins'],
                        $row['subscription_coins'],
                        $row['entry_coins'],
                        $row['total_coins'],
                    ]);
                }
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function weeklyUsers(Carbon $fromDate, Carbon $toDate, string $query, int $limit): array
    {
        $rows = LeaderboardDailyStat::query()
            ->join('users', 'users.id', '=', 'leaderboard_daily_stats.subject_id')
            ->leftJoin('user_levels', 'user_levels.id', '=', 'users.level_id')
            ->where('leaderboard_daily_stats.subject_type', 'user')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ])
            ->when(trim($query) !== '', function ($builder) use ($query) {
                $term = '%'.trim($query).'%';
                $builder->where(function ($inner) use ($term) {
                    $inner->where('users.name', 'like', $term)
                        ->orWhere('users.email', 'like', $term)
                        ->orWhere('users.id', 'like', $term);
                });
            })
            ->groupBy('users.id', 'users.name', 'users.email', 'user_levels.level')
            ->selectRaw('
                users.id as id,
                users.name as name,
                users.email as email,
                user_levels.level as level,
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

        return $this->rankRows($rows, function ($row, int $rank): array {
            return [
                'rank' => $rank,
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) ($row->email ?? ''),
                'level' => $row->level !== null ? (int) $row->level : null,
                'gift_coins' => (int) ($row->gift_coins ?? 0),
                'call_coins' => (int) ($row->call_coins ?? 0),
                'subscription_coins' => (int) ($row->subscription_coins ?? 0),
                'entry_coins' => (int) ($row->entry_coins ?? 0),
                'total_coins' => (int) ($row->total_coins ?? 0),
            ];
        });
    }

    private function weeklyHosts(Carbon $fromDate, Carbon $toDate, string $query, int $limit): array
    {
        $rows = LeaderboardDailyStat::query()
            ->join('hosts', 'hosts.id', '=', 'leaderboard_daily_stats.subject_id')
            ->join('users', 'users.id', '=', 'hosts.user_id')
            ->leftJoin('agencies', 'agencies.id', '=', 'hosts.agency_id')
            ->where('leaderboard_daily_stats.subject_type', 'host')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ])
            ->when(trim($query) !== '', function ($builder) use ($query) {
                $term = '%'.trim($query).'%';
                $builder->where(function ($inner) use ($term) {
                    $inner->where('users.name', 'like', $term)
                        ->orWhere('hosts.stage_name', 'like', $term)
                        ->orWhere('agencies.name', 'like', $term)
                        ->orWhere('hosts.id', 'like', $term);
                });
            })
            ->groupBy('hosts.id', 'hosts.user_id', 'users.name', 'hosts.stage_name', 'agencies.name')
            ->selectRaw('
                hosts.id as host_id,
                hosts.user_id as host_user_id,
                COALESCE(NULLIF(hosts.stage_name, \'\'), users.name) as display_name,
                agencies.name as agency_name,
                SUM(leaderboard_daily_stats.gift_coins) as gift_coins,
                SUM(leaderboard_daily_stats.call_coins) as call_coins,
                SUM(leaderboard_daily_stats.total_coins) as total_coins
            ')
            ->orderByDesc('total_coins')
            ->orderBy('hosts.id')
            ->limit($limit)
            ->get();

        return $this->rankRows($rows, function ($row, int $rank): array {
            return [
                'rank' => $rank,
                'host_id' => (int) $row->host_id,
                'host_user_id' => (int) $row->host_user_id,
                'name' => (string) $row->display_name,
                'agency_name' => (string) ($row->agency_name ?? 'Independent'),
                'gift_coins' => (int) ($row->gift_coins ?? 0),
                'call_coins' => (int) ($row->call_coins ?? 0),
                'total_coins' => (int) ($row->total_coins ?? 0),
            ];
        });
    }

    private function weeklyAgencies(Carbon $fromDate, Carbon $toDate, string $query, int $limit): array
    {
        $rows = LeaderboardDailyStat::query()
            ->join('agencies', 'agencies.id', '=', 'leaderboard_daily_stats.subject_id')
            ->where('leaderboard_daily_stats.subject_type', 'agency')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ])
            ->when(trim($query) !== '', function ($builder) use ($query) {
                $term = '%'.trim($query).'%';
                $builder->where(function ($inner) use ($term) {
                    $inner->where('agencies.name', 'like', $term)
                        ->orWhere('agencies.id', 'like', $term);
                });
            })
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

        $hostCounts = LeaderboardDailyStat::query()
            ->join('hosts', 'hosts.id', '=', 'leaderboard_daily_stats.subject_id')
            ->where('leaderboard_daily_stats.subject_type', 'host')
            ->whereNotNull('hosts.agency_id')
            ->whereBetween('leaderboard_daily_stats.stat_date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ])
            ->selectRaw('hosts.agency_id as agency_id, COUNT(DISTINCT hosts.id) as host_count')
            ->groupBy('hosts.agency_id')
            ->pluck('host_count', 'agency_id');

        return $this->rankRows($rows, function ($row, int $rank) use ($hostCounts): array {
            return [
                'rank' => $rank,
                'agency_id' => (int) $row->agency_id,
                'name' => (string) $row->name,
                'host_count' => (int) ($hostCounts[(int) $row->agency_id] ?? 0),
                'gift_coins' => (int) ($row->gift_coins ?? 0),
                'call_coins' => (int) ($row->call_coins ?? 0),
                'total_coins' => (int) ($row->total_coins ?? 0),
            ];
        });
    }

    private function resolveDateRange(Request $request): array
    {
        $selectedWeek = trim((string) $request->input('week', ''));

        if ($selectedWeek !== '') {
            $start = $this->parseWeekInput($selectedWeek);
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);

            return [$start, $end, $selectedWeek];
        }

        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        if ($fromInput || $toInput) {
            $fromDate = $fromInput
                ? Carbon::parse($fromInput, self::BUSINESS_TIMEZONE)->startOfDay()
                : now(self::BUSINESS_TIMEZONE)->startOfWeek(Carbon::MONDAY);
            $toDate = $toInput
                ? Carbon::parse($toInput, self::BUSINESS_TIMEZONE)->endOfDay()
                : now(self::BUSINESS_TIMEZONE)->endOfWeek(Carbon::SUNDAY);

            return [$fromDate, $toDate, ''];
        }

        $weekStart = now(self::BUSINESS_TIMEZONE)->startOfWeek(Carbon::MONDAY);
        $weekEnd = now(self::BUSINESS_TIMEZONE)->endOfWeek(Carbon::SUNDAY);

        return [$weekStart, $weekEnd, $weekStart->format('o-\WW')];
    }

    private function rangeLabel(Carbon $fromDate, Carbon $toDate): string
    {
        return sprintf(
            '%s to %s · IST Monday-Sunday week logic',
            $fromDate->format('d M Y'),
            $toDate->format('d M Y'),
        );
    }

    private function rankRows($rows, callable $mapper): array
    {
        $rank = 0;

        return $rows->map(function ($row) use (&$rank, $mapper) {
            $rank++;

            return $mapper($row, $rank);
        })->values()->all();
    }

    private function parseWeekInput(string $selectedWeek): Carbon
    {
        if (preg_match('/^(?<year>\d{4})-W(?<week>\d{2})$/', $selectedWeek, $matches) === 1) {
            return Carbon::now(self::BUSINESS_TIMEZONE)
                ->setISODate((int) $matches['year'], (int) $matches['week'])
                ->startOfWeek(Carbon::MONDAY);
        }

        return now(self::BUSINESS_TIMEZONE)->startOfWeek(Carbon::MONDAY);
    }
}
