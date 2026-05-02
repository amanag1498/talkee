<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('host_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('host_id')->constrained()->cascadeOnDelete();
            $t->string('path', 1024); // storage path or URL
            $t->unsignedTinyInteger('sort')->default(0); // 0..5
            $t->timestamps();

            $t->unique(['host_id', 'sort']); // at most one photo per slot (0..5)
            $t->index(['host_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_photos');
    }
};
