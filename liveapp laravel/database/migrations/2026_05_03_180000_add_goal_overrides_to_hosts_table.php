<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->string('goal_followers')->nullable()->after('video_call_rate_per_minute');
            $table->string('goal_weekly_live_minutes')->nullable()->after('goal_followers');
            $table->string('goal_weekly_gifted_coins')->nullable()->after('goal_weekly_live_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->dropColumn([
                'goal_followers',
                'goal_weekly_live_minutes',
                'goal_weekly_gifted_coins',
            ]);
        });
    }
};
