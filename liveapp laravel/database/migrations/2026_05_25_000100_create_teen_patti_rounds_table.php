<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teen_patti_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('round_key')->unique();
            $table->string('status', 24)->default('open')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('locks_at')->index();
            $table->timestamp('ends_at')->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->char('winning_pot', 1)->nullable();
            $table->string('winning_strategy', 32)->nullable();
            $table->json('winning_hand')->nullable();
            $table->json('losing_hand_one')->nullable();
            $table->json('losing_hand_two')->nullable();
            $table->unsignedBigInteger('total_bet_a')->default(0);
            $table->unsignedBigInteger('total_bet_b')->default(0);
            $table->unsignedBigInteger('total_bet_c')->default(0);
            $table->unsignedInteger('total_bets_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teen_patti_rounds');
    }
};
