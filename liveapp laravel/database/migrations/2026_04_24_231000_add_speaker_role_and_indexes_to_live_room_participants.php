<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE live_room_participants MODIFY role ENUM('viewer','speaker','host','moderator') NOT NULL DEFAULT 'viewer'");
        }

        Schema::table('live_room_participants', function (Blueprint $table) {
            $table->index(['live_room_id', 'user_id', 'left_at'], 'lrp_room_user_left_idx');
        });
    }

    public function down(): void
    {
        Schema::table('live_room_participants', function (Blueprint $table) {
            $table->dropIndex('lrp_room_user_left_idx');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE live_room_participants MODIFY role ENUM('viewer','host','moderator') NOT NULL DEFAULT 'viewer'");
        }
    }
};
