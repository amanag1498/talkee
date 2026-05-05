<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('profile_frames')
            ->where('slug', 'lion-king-crest')
            ->update(['unlock_type' => 'agency_reward']);

        DB::table('profile_frames')
            ->where('slug', 'host-sovereign-crest')
            ->update(['unlock_type' => 'host_reward']);
    }

    public function down(): void
    {
        DB::table('profile_frames')
            ->whereIn('slug', ['lion-king-crest', 'host-sovereign-crest'])
            ->update(['unlock_type' => 'free_catalog']);
    }
};
