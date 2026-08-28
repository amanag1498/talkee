<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharge_plans', function (Blueprint $table) {
            $table->string('apple_product_id')->nullable()->after('amount_rupees');
            $table->index('apple_product_id', 'recharge_plans_apple_product_idx');
        });

        Schema::table('payment_orders', function (Blueprint $table) {
            $table->string('apple_transaction_id')->nullable()->after('gateway_payment_id');
            $table->string('store_product_id')->nullable()->after('apple_transaction_id');
            $table->string('store_environment', 20)->nullable()->after('store_product_id');
            $table->decimal('store_price', 12, 3)->nullable()->after('store_environment');
            $table->string('store_currency', 3)->nullable()->after('store_price');
            $table->unique('apple_transaction_id', 'payment_orders_apple_transaction_unique');
        });

        DB::table('recharge_plans')
            ->select(['id', 'total_coins'])
            ->orderBy('id')
            ->get()
            ->each(function (object $plan): void {
                DB::table('recharge_plans')
                    ->where('id', $plan->id)
                    ->update([
                        'apple_product_id' => 'com.techybugs.talkee.coins.'.(int) $plan->total_coins,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropUnique('payment_orders_apple_transaction_unique');
            $table->dropColumn([
                'apple_transaction_id',
                'store_product_id',
                'store_environment',
                'store_price',
                'store_currency',
            ]);
        });

        Schema::table('recharge_plans', function (Blueprint $table) {
            $table->dropIndex('recharge_plans_apple_product_idx');
            $table->dropColumn('apple_product_id');
        });
    }
};
