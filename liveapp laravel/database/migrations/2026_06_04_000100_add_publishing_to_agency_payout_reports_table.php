<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_payout_reports', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('approved_at');
            $table->foreignId('published_by_admin_user_id')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agency_payout_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by_admin_user_id');
            $table->dropColumn('published_at');
        });
    }
};
