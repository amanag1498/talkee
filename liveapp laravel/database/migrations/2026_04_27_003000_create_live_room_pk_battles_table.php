<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_room_pk_battles', function (Blueprint $table) {
            $table->id();
            $table->string('battle_id', 64)->unique();
            $table->foreignId('room_a_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('room_b_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('host_a_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('host_b_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('invited_by_host_id')->constrained('hosts')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('duration_seconds')->default(300);
            $table->unsignedBigInteger('score_a')->default(0);
            $table->unsignedBigInteger('score_b')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('winner_room_id')->nullable()->constrained('live_rooms')->nullOnDelete();
            $table->string('end_reason', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['room_a_id', 'status']);
            $table->index(['room_b_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_pk_battles');
    }
};
