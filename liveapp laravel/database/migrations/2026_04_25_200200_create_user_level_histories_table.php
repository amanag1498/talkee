<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_level_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_level_id')->nullable()->constrained('user_levels')->nullOnDelete();
            $table->foreignId('new_level_id')->constrained('user_levels')->cascadeOnDelete();
            $table->unsignedBigInteger('lifetime_spend_coins')->default(0);
            $table->foreignId('triggered_by_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'user_level_histories_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_level_histories');
    }
};
