<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('area', 80)->index();
            $table->string('action', 120)->index();
            $table->string('entity_type', 160)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'admin_action_audits_entity_idx');
            $table->index(['target_user_id', 'created_at'], 'admin_action_audits_target_user_created_idx');
            $table->index(['admin_user_id', 'created_at'], 'admin_action_audits_admin_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_audits');
    }
};
