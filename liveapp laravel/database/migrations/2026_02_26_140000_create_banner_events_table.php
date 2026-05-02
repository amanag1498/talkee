<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('banner_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained('banners')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 20)->index(); // impression|click
            $table->string('placement', 40)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('role', 20)->nullable();
            $table->string('session_id', 120)->nullable()->index();
            $table->json('context')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['banner_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_events');
    }
};

