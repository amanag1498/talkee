<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('live_rooms', 'max_speakers')) {
                $table->unsignedInteger('max_speakers')->default(4)->after('peak_viewers');
            }

            $table->index(['status', 'max_speakers'], 'live_rooms_status_max_speakers_idx');
        });
    }

    public function down(): void
    {
        Schema::table('live_rooms', function (Blueprint $table) {
            $table->dropIndex('live_rooms_status_max_speakers_idx');

            if (Schema::hasColumn('live_rooms', 'max_speakers')) {
                $table->dropColumn('max_speakers');
            }
        });
    }
};
