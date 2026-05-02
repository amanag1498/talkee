<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('hosts', function (Blueprint $t) {
      $t->id();
      $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $t->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
      $t->string('stage_name')->nullable();
      $t->string('contact_phone')->nullable();
      $t->string('country')->nullable();
      $t->string('city')->nullable();
      $t->text('bio')->nullable();
      $t->json('kyc')->nullable();
      $t->timestamps();
      $t->unique('user_id');
    });
  }
  public function down(): void { Schema::dropIfExists('hosts'); }
};
