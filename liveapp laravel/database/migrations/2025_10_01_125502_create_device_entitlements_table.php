<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('device_entitlements', function (Blueprint $table) {
      $table->id();
      $table->string('device_id', 191)->unique(); // hard stop: one entitlement per device
      $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
      $table->foreignId('subscription_id')->nullable()->constrained('user_subscriptions')->nullOnDelete();
      $table->string('entitlement_type', 50)->default('signup_gift'); // future-proof
      $table->json('meta')->nullable();
      $table->timestamp('granted_at')->nullable();
      $table->timestamps();
    });
  }

  public function down(): void {
    Schema::dropIfExists('device_entitlements');
  }
};

