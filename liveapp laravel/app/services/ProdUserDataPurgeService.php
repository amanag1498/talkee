<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProdUserDataPurgeService
{
    public const CONFIRMATION_TOKEN = 'DELETE_USER_HOST_AGENCY_DATA';

    /**
     * Catalog/master data intentionally preserved by this purge:
     * user_levels, themes, profile_frames, recharge_plans, gifts,
     * entry_packs, subscription_plans, app_settings, banners, moderation_rules.
     */
    private const FULL_DELETE_TABLES = [
        'agency_coin_transfers',
        'agency_wallet_transactions',
        'agency_wallets',
        'agency_payout_report_items',
        'agency_payout_reports',
        'call_earning_ledgers',
        'call_sessions',
        'live_room_pk_events',
        'live_room_pk_battles',
        'live_room_gift_earning_ledgers',
        'live_room_gifts',
        'live_room_reminders',
        'live_room_seat_requests',
        'live_room_participants',
        'live_room_admin_audits',
        'live_rooms',
        'host_photos',
        'host_followers',
        'host_availabilities',
        'host_user_blocks',
        'room_user_kicks',
        'user_reports',
        'moderation_actions',
        'unblock_requests',
        'host_enroll_requests',
        'host_requests',
        'agency_requests',
        'device_entitlements',
        'device_push_tokens',
        'device_blocks',
        'notifications',
        'user_notifications',
        'payment_orders',
        'wallet_transactions',
        'wallets',
        'user_subscriptions',
        'user_entry_packs',
        'user_theme_unlocks',
        'user_theme_preferences',
        'user_profile_frames',
        'user_level_histories',
        'level_spend_events',
        'leaderboard_daily_stats',
        'teen_patti_bets',
        'teen_patti_payouts',
        'teen_patti_rounds',
        'greedy_bets',
        'greedy_payouts',
        'greedy_rounds',
        'user_game_accesses',
        'banner_events',
        'admin_action_audits',
        'password_reset_tokens',
    ];

    private const FINAL_DELETE_TABLES = [
        'hosts',
        'agencies',
    ];

    public function plan(int $keepUserId = 1): array
    {
        $this->assertKeepUserExists($keepUserId);

        $rows = [];

        foreach ([...self::FULL_DELETE_TABLES, ...self::FINAL_DELETE_TABLES] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows[] = [
                'operation' => 'delete_all',
                'table' => $table,
                'rows' => DB::table($table)->count(),
            ];
        }

        foreach ($this->scopedDeleteCounts($keepUserId) as $row) {
            $rows[] = $row;
        }

        $rows[] = [
            'operation' => 'delete_except',
            'table' => 'users',
            'rows' => DB::table('users')->where('id', '!=', $keepUserId)->count(),
        ];

        return $rows;
    }

    public function purge(int $keepUserId = 1): array
    {
        $plan = $this->plan($keepUserId);

        $this->withoutForeignKeyChecks(function () use ($keepUserId): void {
            DB::transaction(function () use ($keepUserId): void {
                foreach (self::FULL_DELETE_TABLES as $table) {
                    $this->deleteAllIfExists($table);
                }

                $this->deleteRolePivotsExcept($keepUserId);
                $this->deleteSessionsExcept($keepUserId);
                $this->deletePersonalAccessTokensExcept($keepUserId);

                foreach (self::FINAL_DELETE_TABLES as $table) {
                    $this->deleteAllIfExists($table);
                }

                DB::table('users')->where('id', '!=', $keepUserId)->delete();
            });
        });

        return $plan;
    }

    public function autoIncrementResetPlan(int $keepUserId = 1): array
    {
        $this->assertKeepUserExists($keepUserId);

        if (! $this->supportsAutoIncrementReset()) {
            return [[
                'operation' => 'skip',
                'table' => '*',
                'rows' => 0,
                'next_auto_increment' => null,
                'status' => 'unsupported_driver: '.DB::connection()->getDriverName(),
            ]];
        }

        $rows = [];
        foreach ($this->autoIncrementResetTables() as $table) {
            if (! Schema::hasTable($table) || ! $this->hasAutoIncrementColumn($table)) {
                continue;
            }

            $rowCount = DB::table($table)->count();
            $nextAutoIncrement = $table === 'users'
                ? $this->nextUsersAutoIncrement($keepUserId)
                : 1;

            $rows[] = [
                'operation' => 'reset_auto_increment',
                'table' => $table,
                'rows' => $rowCount,
                'next_auto_increment' => $nextAutoIncrement,
                'status' => $table === 'users' || $rowCount === 0
                    ? 'ready'
                    : 'skipped_not_empty',
            ];
        }

        return $rows;
    }

    public function resetAutoIncrements(int $keepUserId = 1): array
    {
        $plan = $this->autoIncrementResetPlan($keepUserId);

        if (! $this->supportsAutoIncrementReset()) {
            return $plan;
        }

        foreach ($plan as $row) {
            if (($row['status'] ?? null) !== 'ready') {
                continue;
            }

            $this->resetTableAutoIncrement(
                $row['table'],
                (int) $row['next_auto_increment'],
            );
        }

        return $plan;
    }

    private function scopedDeleteCounts(int $keepUserId): array
    {
        $rows = [];

        if (Schema::hasTable('model_has_roles')) {
            $rows[] = [
                'operation' => 'delete_except',
                'table' => 'model_has_roles',
                'rows' => DB::table('model_has_roles')->where('model_id', '!=', $keepUserId)->count(),
            ];
        }

        if (Schema::hasTable('model_has_permissions')) {
            $rows[] = [
                'operation' => 'delete_except',
                'table' => 'model_has_permissions',
                'rows' => DB::table('model_has_permissions')->where('model_id', '!=', $keepUserId)->count(),
            ];
        }

        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            $rows[] = [
                'operation' => 'delete_except',
                'table' => 'sessions',
                'rows' => DB::table('sessions')
                    ->whereNull('user_id')
                    ->orWhere('user_id', '!=', $keepUserId)
                    ->count(),
            ];
        }

        if (Schema::hasTable('personal_access_tokens') && Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            $rows[] = [
                'operation' => 'delete_except',
                'table' => 'personal_access_tokens',
                'rows' => DB::table('personal_access_tokens')->where('tokenable_id', '!=', $keepUserId)->count(),
            ];
        }

        return $rows;
    }

    private function deleteRolePivotsExcept(int $keepUserId): void
    {
        if (Schema::hasTable('model_has_roles')) {
            DB::table('model_has_roles')->where('model_id', '!=', $keepUserId)->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->where('model_id', '!=', $keepUserId)->delete();
        }
    }

    private function deleteSessionsExcept(int $keepUserId): void
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasColumn('sessions', 'user_id')) {
            return;
        }

        DB::table('sessions')
            ->whereNull('user_id')
            ->orWhere('user_id', '!=', $keepUserId)
            ->delete();
    }

    private function deletePersonalAccessTokensExcept(int $keepUserId): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        DB::table('personal_access_tokens')->where('tokenable_id', '!=', $keepUserId)->delete();
    }

    private function deleteAllIfExists(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->delete();
    }

    private function autoIncrementResetTables(): array
    {
        return [...self::FULL_DELETE_TABLES, ...self::FINAL_DELETE_TABLES, 'users'];
    }

    private function supportsAutoIncrementReset(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasAutoIncrementColumn(string $table): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('EXTRA', 'like', '%auto_increment%')
            ->exists();
    }

    private function nextUsersAutoIncrement(int $keepUserId): int
    {
        $maxUserId = (int) DB::table('users')->max('id');

        return max($keepUserId + 1, $maxUserId + 1);
    }

    private function resetTableAutoIncrement(string $table, int $nextAutoIncrement): void
    {
        DB::statement(sprintf(
            'ALTER TABLE `%s` AUTO_INCREMENT = %d',
            str_replace('`', '``', $table),
            $nextAutoIncrement,
        ));
    }

    private function assertKeepUserExists(int $keepUserId): void
    {
        if (! Schema::hasTable('users') || ! DB::table('users')->where('id', $keepUserId)->exists()) {
            throw new RuntimeException("Keep user id {$keepUserId} does not exist.");
        }
    }

    private function withoutForeignKeyChecks(callable $callback): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
