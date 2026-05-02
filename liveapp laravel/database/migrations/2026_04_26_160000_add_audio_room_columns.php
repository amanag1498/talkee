<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('live_rooms', 'room_type')) {
                $table->string('room_type', 16)->default('video')->after('title');
            }
            if (!Schema::hasColumn('live_rooms', 'max_participants')) {
                $table->unsignedInteger('max_participants')->default(50)->after('max_speakers');
            }
            if (!Schema::hasColumn('live_rooms', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('max_participants');
            }
            if (!Schema::hasColumn('live_rooms', 'topic')) {
                $table->string('topic', 120)->nullable()->after('is_locked');
            }
            if (!Schema::hasColumn('live_rooms', 'language')) {
                $table->string('language', 32)->nullable()->after('topic');
            }

        });
        if (Schema::hasTable('live_rooms') && !Schema::hasIndex('live_rooms', 'live_rooms_type_status_started_idx')) {
            Schema::table('live_rooms', function (Blueprint $table) {
                $table->index(['room_type', 'status', 'started_at'], 'live_rooms_type_status_started_idx');
            });
        }
        if (Schema::hasTable('live_rooms') && !Schema::hasIndex('live_rooms', 'live_rooms_status_ended_audio_idx')) {
            Schema::table('live_rooms', function (Blueprint $table) {
                $table->index(['status', 'ended_at'], 'live_rooms_status_ended_audio_idx');
            });
        }

        Schema::table('live_room_participants', function (Blueprint $table) {
            if (Schema::hasColumn('live_room_participants', 'role')) {
                $table->string('role', 20)->default('viewer')->change();
            }
            if (!Schema::hasColumn('live_room_participants', 'muted_by_host')) {
                $table->boolean('muted_by_host')->default(false)->after('role');
            }
            if (!Schema::hasColumn('live_room_participants', 'removed_by_host')) {
                $table->boolean('removed_by_host')->default(false)->after('muted_by_host');
            }
            if (!Schema::hasColumn('live_room_participants', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('left_at');
            }

        });
        if (Schema::hasTable('live_room_participants') && !Schema::hasIndex('live_room_participants', 'lrp_room_role_left_idx')) {
            Schema::table('live_room_participants', function (Blueprint $table) {
                $table->index(['live_room_id', 'role', 'left_at'], 'lrp_room_role_left_idx');
            });
        }
        if (Schema::hasTable('live_room_participants') && !Schema::hasIndex('live_room_participants', 'lrp_room_user_left_idx')) {
            Schema::table('live_room_participants', function (Blueprint $table) {
                $table->index(['live_room_id', 'user_id', 'left_at'], 'lrp_room_user_left_idx');
            });
        }

        if (Schema::hasTable('live_room_seat_requests') && !Schema::hasIndex('live_room_seat_requests', 'lrsr_room_user_status_audio_idx')) {
            Schema::table('live_room_seat_requests', function (Blueprint $table) {
                $table->index(['live_room_id', 'user_id', 'status'], 'lrsr_room_user_status_audio_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('live_room_seat_requests') && Schema::hasIndex('live_room_seat_requests', 'lrsr_room_user_status_audio_idx')) {
            Schema::table('live_room_seat_requests', function (Blueprint $table) {
                $table->dropIndex('lrsr_room_user_status_audio_idx');
            });
        }

        Schema::table('live_room_participants', function (Blueprint $table) {
            if (Schema::hasColumn('live_room_participants', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
            if (Schema::hasColumn('live_room_participants', 'removed_by_host')) {
                $table->dropColumn('removed_by_host');
            }
            if (Schema::hasColumn('live_room_participants', 'muted_by_host')) {
                $table->dropColumn('muted_by_host');
            }
        });
        if (Schema::hasTable('live_room_participants') && Schema::hasIndex('live_room_participants', 'lrp_room_role_left_idx')) {
            Schema::table('live_room_participants', function (Blueprint $table) {
                $table->dropIndex('lrp_room_role_left_idx');
            });
        }
        if (Schema::hasTable('live_room_participants') && Schema::hasIndex('live_room_participants', 'lrp_room_user_left_idx')) {
            Schema::table('live_room_participants', function (Blueprint $table) {
                $table->dropIndex('lrp_room_user_left_idx');
            });
        }

        if (Schema::hasTable('live_rooms') && Schema::hasIndex('live_rooms', 'live_rooms_type_status_started_idx')) {
            Schema::table('live_rooms', function (Blueprint $table) {
                $table->dropIndex('live_rooms_type_status_started_idx');
            });
        }
        if (Schema::hasTable('live_rooms') && Schema::hasIndex('live_rooms', 'live_rooms_status_ended_audio_idx')) {
            Schema::table('live_rooms', function (Blueprint $table) {
                $table->dropIndex('live_rooms_status_ended_audio_idx');
            });
        }
        Schema::table('live_rooms', function (Blueprint $table) {
            foreach (['language', 'topic', 'is_locked', 'max_participants', 'room_type'] as $column) {
                if (Schema::hasColumn('live_rooms', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
