<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('live_room_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // null = guest
            $t->string('session_id', 64)->nullable();   // for guests/clients
            $t->enum('role', ['viewer','speaker','host','moderator'])->default('viewer');
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->string('device', 80)->nullable();
            $t->string('country', 2)->nullable();       // ISO2; optional
            $t->string('ip_address', 45)->nullable();   // IPv4/6
            $t->text('user_agent')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->index(['live_room_id', 'joined_at']);
            $t->index(['user_id', 'live_room_id']);
            $t->index(['session_id', 'live_room_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('live_room_participants');
    }
};
