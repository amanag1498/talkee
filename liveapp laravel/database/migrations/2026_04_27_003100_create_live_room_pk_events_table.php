<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_room_pk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pk_battle_id')->constrained('live_room_pk_battles')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 24);
            $table->unsignedBigInteger('coins')->default(0);
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('gift_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['pk_battle_id', 'created_at']);
            $table->unique('wallet_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_pk_events');
    }
};
