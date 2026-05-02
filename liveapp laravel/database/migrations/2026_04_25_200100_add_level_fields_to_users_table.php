<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('level_id')->nullable()->after('avatar_url')->constrained('user_levels')->nullOnDelete();
            $table->unsignedBigInteger('lifetime_spend_coins')->default(0)->after('level_id');
            $table->index('lifetime_spend_coins', 'users_lifetime_spend_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('level_id');
            $table->dropIndex('users_lifetime_spend_idx');
            $table->dropColumn('lifetime_spend_coins');
        });
    }
};
