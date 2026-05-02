<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('device_id');
            $table->date('last_login_date')->nullable()->after('last_login_at');
            $table->unsignedInteger('current_login_streak_days')->default(0)->after('last_login_date');
            $table->unsignedInteger('max_login_streak_days')->default(0)->after('current_login_streak_days');
            $table->string('referral_code')->nullable()->unique()->after('max_login_streak_days');
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'last_login_at',
                'last_login_date',
                'current_login_streak_days',
                'max_login_streak_days',
                'referral_code',
            ]);
        });
    }
};
