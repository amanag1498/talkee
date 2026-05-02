<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_theme_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('theme_key');
            $table->string('source');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'theme_key'], 'user_theme_unlocks_user_theme_unique');
            $table->index(['theme_key', 'expires_at'], 'user_theme_unlocks_theme_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_theme_unlocks');
    }
};
