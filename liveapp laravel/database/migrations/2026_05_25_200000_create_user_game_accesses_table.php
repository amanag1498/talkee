<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_game_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game_key', 40);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'game_key'], 'user_game_accesses_user_game_unique');
            $table->index(['game_key', 'user_id'], 'user_game_accesses_game_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_game_accesses');
    }
};
