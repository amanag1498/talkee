<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('live_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('host_id')->constrained('hosts')->cascadeOnDelete(); // link to Host
            $t->string('room_id', 100)->unique();       // external/SDK room id
            $t->string('title', 150)->nullable();
            $t->enum('status', ['scheduled','live','ended'])->default('scheduled');
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('peak_viewers')->default(0);
            $t->json('meta')->nullable();               // any provider-specific data
            $t->timestamps();

            $t->index(['host_id', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('live_rooms');
    }
};
