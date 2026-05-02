<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $t->enum('status', ['active','cancelled','expired'])->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('last_purchased_at')->nullable();
            $t->timestamps();
            $t->index(['user_id','status']);
            $t->index('ends_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('user_subscriptions');
    }
};

