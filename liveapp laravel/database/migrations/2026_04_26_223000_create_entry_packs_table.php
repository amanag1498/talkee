<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('price_coins');
            $table->string('svg_url', 2048)->nullable();
            $table->string('animation_style', 32)->default('banner');
            $table->integer('priority')->default(1);
            $table->unsignedInteger('duration_ms')->default(3000);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_packs');
    }
};
