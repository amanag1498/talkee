<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entry_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_pack_id')->constrained('entry_packs')->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamp('purchased_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('purchase_key', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'expires_at']);
            $table->index(['entry_pack_id', 'is_active']);
            $table->unique(['user_id', 'purchase_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entry_packs');
    }
};
