<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('live_rooms', 'end_reason')) {
                $table->string('end_reason', 50)->nullable()->after('ended_at');
            }
            if (!Schema::hasColumn('live_rooms', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('end_reason');
            }

            $table->index(['status', 'ended_at'], 'live_rooms_status_ended_idx');
            $table->index('last_activity_at', 'live_rooms_last_activity_idx');
            $table->index('end_reason', 'live_rooms_end_reason_idx');
        });

        Schema::table('live_room_participants', function (Blueprint $table) {
            $table->index(['live_room_id', 'left_at'], 'lrp_room_left_idx');
            $table->index(['live_room_id', 'role', 'left_at'], 'lrp_room_role_left_idx');
        });
    }

    public function down(): void
    {
        Schema::table('live_room_participants', function (Blueprint $table) {
            $table->dropIndex('lrp_room_left_idx');
            $table->dropIndex('lrp_room_role_left_idx');
        });

        Schema::table('live_rooms', function (Blueprint $table) {
            $table->dropIndex('live_rooms_status_ended_idx');
            $table->dropIndex('live_rooms_last_activity_idx');
            $table->dropIndex('live_rooms_end_reason_idx');

            if (Schema::hasColumn('live_rooms', 'last_activity_at')) {
                $table->dropColumn('last_activity_at');
            }
            if (Schema::hasColumn('live_rooms', 'end_reason')) {
                $table->dropColumn('end_reason');
            }
        });
    }
};
