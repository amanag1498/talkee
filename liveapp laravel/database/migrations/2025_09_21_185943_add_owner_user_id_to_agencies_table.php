<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'owner_user_id')) {
                // Make it nullable if you already have rows; you can backfill later and make non-nullable
                $table->foreignId('owner_user_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('users')
                      ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'owner_user_id')) {
                $table->dropConstrainedForeignId('owner_user_id');
            }
        });
    }
};
