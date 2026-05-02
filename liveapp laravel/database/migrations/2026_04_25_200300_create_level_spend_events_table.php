<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_spend_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained('wallet_transactions')->cascadeOnDelete();
            $table->unsignedBigInteger('spend_coins');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'level_spend_events_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_spend_events');
    }
};
