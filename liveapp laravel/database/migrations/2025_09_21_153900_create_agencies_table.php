<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('agencies', function (Blueprint $t) {
      $t->id();
      $t->foreignId('owner_user_id')->constrained('users');
      $t->string('name');
      $t->string('legal_name')->nullable();
      $t->string('contact_email')->nullable();
      $t->string('contact_phone')->nullable();
      $t->text('notes')->nullable();
      $t->timestamps();
    });
  }
  public function down(): void { Schema::dropIfExists('agencies'); }
};
