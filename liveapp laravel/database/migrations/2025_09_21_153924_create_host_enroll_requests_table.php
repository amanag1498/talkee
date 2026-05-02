<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('host_enroll_requests', function (Blueprint $t) {
      $t->id();
      $t->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
      $t->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
      $t->text('message')->nullable();
      $t->enum('status',['pending','approved','rejected'])->default('pending');
      $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamp('reviewed_at')->nullable();
      $t->text('review_notes')->nullable();
      $t->timestamps();
      $t->unique(['host_user_id','agency_id','status']); // prevent duplicate pending
    });
  }
  public function down(): void { Schema::dropIfExists('host_enroll_requests'); }
};
