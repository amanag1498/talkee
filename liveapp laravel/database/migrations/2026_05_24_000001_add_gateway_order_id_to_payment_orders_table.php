<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_orders', 'gateway_order_id')) {
                $table->string('gateway_order_id')->nullable()->after('gateway');
                $table->index('gateway_order_id', 'payment_orders_gateway_order_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            if (Schema::hasColumn('payment_orders', 'gateway_order_id')) {
                $table->dropIndex('payment_orders_gateway_order_idx');
                $table->dropColumn('gateway_order_id');
            }
        });
    }
};
