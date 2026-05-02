<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('agency_requests', function (Blueprint $t) {
      $t->id();
      $t->foreignId('user_id')->constrained('users');
      $t->string('agency_name');
      $t->string('legal_name')->nullable();
      $t->string('contact_phone')->nullable();
      $t->string('website')->nullable();
      $t->text('about')->nullable();
      $t->enum('status',['pending','approved','rejected'])->default('pending');
      $t->foreignId('reviewed_by')->nullable()->constrained('users');
      $t->timestamp('reviewed_at')->nullable();
      $t->text('review_notes')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('agency_requests'); }
};
