<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('live_room_admin_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('before_status', 80)->nullable();
            $table->string('after_status', 80)->nullable();
            $table->string('reason', 160)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['live_room_id', 'action'], 'lraa_room_action_idx');
            $table->index(['admin_id', 'created_at'], 'lraa_admin_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_admin_audits');
    }
};
