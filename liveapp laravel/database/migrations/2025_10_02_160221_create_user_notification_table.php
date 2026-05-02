<?php
// database/migrations/2025_10_02_000000_create_user_notifications_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('user_notifications', function (Blueprint $t) {
      $t->id();
      $t->foreignId('user_id')->constrained()->cascadeOnDelete();
      $t->string('type',64)->nullable();
      $t->string('title',160);
      $t->text('body')->nullable();
      $t->json('meta')->nullable();
      $t->timestamp('read_at')->nullable();
      $t->timestamps();
      $t->index(['user_id','created_at']);
    });
  }
  public function down(): void { Schema::dropIfExists('user_notifications'); }
};
