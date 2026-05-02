<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_transactions', 'reference_type')) {
                $table->string('reference_type', 60)->nullable()->after('reference');
            }
            if (!Schema::hasColumn('wallet_transactions', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }
            if (!Schema::hasColumn('wallet_transactions', 'description')) {
                $table->string('description')->nullable()->after('reference_id');
            }
            if (!Schema::hasColumn('wallet_transactions', 'balance_before')) {
                $table->unsignedBigInteger('balance_before')->nullable()->after('description');
            }
            if (!Schema::hasColumn('wallet_transactions', 'balance_after')) {
                $table->unsignedBigInteger('balance_after')->nullable()->after('balance_before');
            }
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id'], 'wallet_tx_reference_pair_idx');
            $table->index(['wallet_id', 'category', 'created_at'], 'wallet_tx_wallet_category_created_idx');
            $table->unique(['wallet_id', 'reference_type', 'reference_id', 'category'], 'wallet_tx_wallet_reference_category_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique('wallet_tx_wallet_reference_category_unique');
            $table->dropIndex('wallet_tx_reference_pair_idx');
            $table->dropIndex('wallet_tx_wallet_category_created_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            foreach (['reference_type', 'reference_id', 'description', 'balance_before', 'balance_after'] as $column) {
                if (Schema::hasColumn('wallet_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
