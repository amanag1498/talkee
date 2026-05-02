<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('live_room_gifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $t->foreignId('gift_id')->constrained('gifts')->cascadeOnDelete();
            $t->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedInteger('quantity')->default(1);
            $t->unsignedBigInteger('coins_per_unit');    // denormalized from gifts.coins (at time of send)
            $t->unsignedBigInteger('total_coins');       // coins_per_unit * quantity (for quick sums)
            $t->string('transaction_id', 100)->nullable(); // link to wallet/payment (if any)
            $t->json('meta')->nullable();                // message, animations, etc.
            $t->timestamps();

            $t->index(['live_room_id', 'created_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('live_room_gifts');
    }
};
