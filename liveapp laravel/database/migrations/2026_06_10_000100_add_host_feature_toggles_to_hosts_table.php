<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->boolean('video_rooms_enabled')->default(true)->after('is_blocked');
            $table->boolean('audio_rooms_enabled')->default(true)->after('video_rooms_enabled');
            $table->boolean('video_calls_enabled')->default(true)->after('audio_rooms_enabled');
            $table->boolean('audio_calls_enabled')->default(true)->after('video_calls_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->dropColumn([
                'video_rooms_enabled',
                'audio_rooms_enabled',
                'video_calls_enabled',
                'audio_calls_enabled',
            ]);
        });
    }
};
