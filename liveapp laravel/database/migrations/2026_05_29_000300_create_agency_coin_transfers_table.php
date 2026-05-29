<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_coin_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_wallet_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agency_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 32);
            $table->unsignedBigInteger('coins');
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'direction', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_coin_transfers');
    }
};
