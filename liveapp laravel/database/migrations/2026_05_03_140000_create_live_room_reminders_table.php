<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_room_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['live_room_id', 'user_id'], 'live_room_reminders_room_user_unique');
            $table->index(['user_id', 'created_at'], 'live_room_reminders_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_reminders');
    }
};
