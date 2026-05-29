<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('coins');
            $table->string('category', 64);
            $table->string('reference')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_agency_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_wallet_transactions');
    }
};
