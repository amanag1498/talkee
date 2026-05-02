<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unlock_type');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_limited')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->foreignId('required_subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->decimal('required_total_recharge', 12, 2)->nullable();
            $table->unsignedBigInteger('required_total_gift_spend')->nullable();
            $table->unsignedInteger('required_user_level')->nullable();
            $table->unsignedInteger('required_host_level')->nullable();
            $table->unsignedInteger('required_login_streak_days')->nullable();
            $table->unsignedInteger('required_referrals')->nullable();
            $table->unsignedInteger('required_host_followers')->nullable();
            $table->string('event_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'themes_active_sort_idx');
            $table->index(['unlock_type', 'is_active'], 'themes_unlock_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
