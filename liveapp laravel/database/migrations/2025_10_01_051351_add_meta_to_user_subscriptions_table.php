<?php

//database/migrations/2025_10_01_000001_add_meta_to_user_subscriptions.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('last_purchased_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
