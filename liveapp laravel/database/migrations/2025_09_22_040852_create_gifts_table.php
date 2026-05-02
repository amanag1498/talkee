<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('gifts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->unsignedBigInteger('coins');              // how many coins this gift costs
            $t->string('gift_url', 2048)->nullable();     // image / lottie / static URL
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('gifts');
    }
};
