<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->date('stat_date');
            $table->unsignedBigInteger('gift_coins')->default(0);
            $table->unsignedBigInteger('call_coins')->default(0);
            $table->unsignedBigInteger('total_coins')->default(0);
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'stat_date'],
                'leaderboard_daily_stats_unique_subject_date'
            );
            $table->index(
                ['subject_type', 'stat_date'],
                'leaderboard_daily_stats_type_date_idx'
            );
            $table->index(
                ['subject_id', 'stat_date'],
                'leaderboard_daily_stats_subject_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_daily_stats');
    }
};
