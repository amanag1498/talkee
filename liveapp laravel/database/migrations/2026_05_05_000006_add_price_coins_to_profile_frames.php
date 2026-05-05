<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_frames', function (Blueprint $table) {
            $table->unsignedInteger('price_coins')->nullable()->after('valid_days');
        });
    }

    public function down(): void
    {
        Schema::table('profile_frames', function (Blueprint $table) {
            $table->dropColumn('price_coins');
        });
    }
};
