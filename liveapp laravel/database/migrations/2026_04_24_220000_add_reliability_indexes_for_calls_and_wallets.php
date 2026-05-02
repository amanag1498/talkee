<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->index('billing_processed_at', 'call_sessions_billing_processed_idx');
            $table->index('end_reason', 'call_sessions_end_reason_idx');
            $table->index('livekit_room_name', 'call_sessions_room_name_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->index('reference', 'wallet_transactions_reference_idx');
            $table->index(['category', 'created_at'], 'wallet_transactions_category_created_idx');
            $table->index('counterparty_user_id', 'wallet_transactions_counterparty_idx');
        });

        Schema::table('host_availabilities', function (Blueprint $table) {
            $table->index('last_seen_at', 'host_avail_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropIndex('call_sessions_billing_processed_idx');
            $table->dropIndex('call_sessions_end_reason_idx');
            $table->dropIndex('call_sessions_room_name_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('wallet_transactions_reference_idx');
            $table->dropIndex('wallet_transactions_category_created_idx');
            $table->dropIndex('wallet_transactions_counterparty_idx');
        });

        Schema::table('host_availabilities', function (Blueprint $table) {
            $table->dropIndex('host_avail_last_seen_idx');
        });
    }
};
