<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('notify_when_online')->default(true);
            $table->boolean('notify_when_available')->default(true);
            $table->timestamp('last_online_notified_at')->nullable();
            $table->timestamp('last_available_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['host_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['host_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_followers');
    }
};
