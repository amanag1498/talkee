<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_id')->nullable()->constrained('hosts')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->enum('type', ['audio', 'video']);
            $table->enum('status', ['requested', 'ringing', 'accepted', 'rejected', 'missed', 'ended', 'failed'])->default('requested');
            $table->string('livekit_room_name', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('billable_minutes')->default(0);
            $table->unsignedBigInteger('coin_rate_per_minute');
            $table->unsignedBigInteger('total_coins_charged')->default(0);
            $table->unsignedBigInteger('host_earning')->default(0);
            $table->unsignedBigInteger('agency_earning')->default(0);
            $table->unsignedBigInteger('platform_earning')->default(0);
            $table->string('end_reason', 100)->nullable();
            $table->timestamp('billing_processed_at')->nullable();
            $table->timestamps();

            $table->index('caller_id');
            $table->index('receiver_id');
            $table->index('host_id');
            $table->index('agency_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['receiver_id', 'status'], 'call_sessions_receiver_status_idx');
            $table->index(['caller_id', 'status'], 'call_sessions_caller_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_sessions');
    }
};
