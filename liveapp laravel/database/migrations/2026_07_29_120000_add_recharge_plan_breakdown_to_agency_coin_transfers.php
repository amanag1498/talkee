<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_coin_transfers', function (Blueprint $table) {
            $table->foreignId('recharge_plan_id')
                ->nullable()
                ->after('direction')
                ->constrained('recharge_plans')
                ->nullOnDelete();
            $table->unsignedBigInteger('bonus_coins')->default(0)->after('coins');
            $table->unsignedBigInteger('total_coins')->default(0)->after('bonus_coins');
        });

        DB::table('agency_coin_transfers')->update([
            'total_coins' => DB::raw('coins'),
        ]);
    }

    public function down(): void
    {
        Schema::table('agency_coin_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recharge_plan_id');
            $table->dropColumn(['bonus_coins', 'total_coins']);
        });
    }
};
