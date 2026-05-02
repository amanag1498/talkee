<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('level')->unique();
            $table->string('title');
            $table->unsignedBigInteger('min_spend_coins')->default(0);
            $table->string('badge_icon')->nullable();
            $table->string('badge_color', 20)->nullable();
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'user_levels_active_sort_idx');
            $table->index('min_spend_coins', 'user_levels_min_spend_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_levels');
    }
};
