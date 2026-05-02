<?php
// database/migrations/2025_10_02_000001_create_device_push_tokens.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('device_push_tokens', function (Blueprint $t) {
      $t->id();
      $t->foreignId('user_id')->constrained()->cascadeOnDelete();
      $t->string('device_id',191)->nullable();
      $t->string('platform',32)->default('android');
      $t->string('token',255)->unique();
      $t->timestamp('last_seen_at')->nullable();
      $t->timestamps();
      $t->index(['user_id','platform']);
    });
  }
  public function down(): void { Schema::dropIfExists('device_push_tokens'); }
};
