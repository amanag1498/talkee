<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharge_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_bonus_coins')->default(0)->after('bonus_coins');
        });

        Schema::table('agency_coin_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_bonus_coins')->default(0)->after('bonus_coins');
        });
    }

    public function down(): void
    {
        Schema::table('agency_coin_transfers', function (Blueprint $table) {
            $table->dropColumn('agency_bonus_coins');
        });

        Schema::table('recharge_plans', function (Blueprint $table) {
            $table->dropColumn('agency_bonus_coins');
        });
    }
};
