<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_room_gift_earning_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_room_gift_id')->unique()->constrained('live_room_gifts')->cascadeOnDelete();
            $table->foreignId('live_room_id')->constrained('live_rooms')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->unsignedBigInteger('total_coins');
            $table->decimal('host_payout_percentage', 5, 2)->default(0.00);
            $table->decimal('agency_payout_percentage', 5, 2)->default(0.00);
            $table->unsignedBigInteger('host_payout_coins')->default(0);
            $table->unsignedBigInteger('agency_payout_coins')->default(0);
            $table->unsignedBigInteger('platform_revenue_coins')->default(0);
            $table->timestamps();

            $table->index(['host_id', 'created_at'], 'gift_earning_ledger_host_created_idx');
            $table->index(['agency_id', 'created_at'], 'gift_earning_ledger_agency_created_idx');
            $table->index(['live_room_id', 'created_at'], 'gift_earning_ledger_room_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_room_gift_earning_ledgers');
    }
};
