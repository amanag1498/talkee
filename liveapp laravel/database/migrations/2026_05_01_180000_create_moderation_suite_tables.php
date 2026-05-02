<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('blocked_by_role', 32)->default('host');
            $table->timestamps();

            $table->unique(['host_user_id', 'blocked_user_id']);
            $table->index('host_user_id');
            $table->index('blocked_user_id');
        });

        Schema::create('room_user_kicks', function (Blueprint $table) {
            $table->id();
            $table->string('room_id', 64);
            $table->string('room_type', 32)->default('video');
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kicked_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('kicked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'room_type']);
            $table->index('host_user_id');
            $table->index('kicked_user_id');
        });

        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room_id', 64)->nullable();
            $table->string('room_type', 32)->nullable();
            $table->string('reason_type', 64);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('reported_user_id');
            $table->index('reason_type');
            $table->index(['host_user_id', 'room_id']);
        });

        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_type', 48);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 32)->nullable();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('room_id', 64)->nullable();
            $table->string('room_type', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('target_user_id');
            $table->index('host_user_id');
            $table->index('action_type');
            $table->index(['room_id', 'room_type']);
        });

        Schema::create('moderation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key')->unique();
            $table->string('rule_type', 32);
            $table->text('pattern')->nullable();
            $table->unsignedInteger('threshold')->nullable();
            $table->string('action', 32);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('severity', 16)->default('low');
            $table->timestamps();

            $table->index(['rule_type', 'is_active']);
        });

        Schema::create('unblock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['host_user_id', 'blocked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unblock_requests');
        Schema::dropIfExists('moderation_rules');
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('user_reports');
        Schema::dropIfExists('room_user_kicks');
        Schema::dropIfExists('host_user_blocks');
    }
};
