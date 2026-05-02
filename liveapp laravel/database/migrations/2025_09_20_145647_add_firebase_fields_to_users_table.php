<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('users', function (Blueprint $t) {
      $t->string('firebase_uid')->nullable()->unique();
      $t->string('avatar_url')->nullable();
      $t->string('provider')->nullable(); // 'google'
    });
  }
  public function down(): void {
    Schema::table('users', function (Blueprint $t) {
      $t->dropColumn(['firebase_uid','avatar_url','provider']);
    });
  }
};
