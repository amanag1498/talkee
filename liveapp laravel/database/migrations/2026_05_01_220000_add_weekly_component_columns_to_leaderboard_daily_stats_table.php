<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboard_daily_stats', function (Blueprint $table) {
            if (!Schema::hasColumn('leaderboard_daily_stats', 'subscription_coins')) {
                $table->unsignedBigInteger('subscription_coins')
                    ->default(0)
                    ->after('call_coins');
            }

            if (!Schema::hasColumn('leaderboard_daily_stats', 'entry_coins')) {
                $table->unsignedBigInteger('entry_coins')
                    ->default(0)
                    ->after('subscription_coins');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leaderboard_daily_stats', function (Blueprint $table) {
            foreach (['subscription_coins', 'entry_coins'] as $column) {
                if (Schema::hasColumn('leaderboard_daily_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
