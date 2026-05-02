<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('host_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('manual_status', ['online', 'offline'])->default('offline');
            $table->enum('socket_status', ['online', 'offline'])->default('offline');
            $table->enum('call_status', ['available', 'busy'])->default('available');
            $table->unsignedBigInteger('current_call_session_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['manual_status', 'socket_status', 'call_status'], 'host_avail_status_idx');
            $table->index('current_call_session_id', 'host_avail_call_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_availabilities');
    }
};
