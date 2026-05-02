<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recharge_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_id')->unique();
            $table->decimal('amount_rupees', 10, 2);
            $table->unsignedInteger('coins');
            $table->unsignedInteger('bonus_coins')->default(0);
            $table->unsignedInteger('total_coins');
            $table->string('status', 20)->default('created');
            $table->string('gateway', 40)->default('mock');
            $table->string('gateway_payment_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'payment_orders_user_status_idx');
            $table->index(['status', 'created_at'], 'payment_orders_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
