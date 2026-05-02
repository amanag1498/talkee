<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('live_room_seat_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled', 'removed', 'expired'])->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->string('remove_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['live_room_id', 'user_id', 'status'], 'lrsr_room_user_status_idx');
            $table->index(['live_room_id', 'requested_at'], 'lrsr_room_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_seat_requests');
    }
};
