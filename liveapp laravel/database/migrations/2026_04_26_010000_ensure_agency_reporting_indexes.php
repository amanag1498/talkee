<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('call_sessions') && !Schema::hasIndex('call_sessions', ['agency_id', 'created_at'])) {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->index(['agency_id', 'created_at'], 'call_sessions_agency_created_idx');
            });
        }

        if (Schema::hasTable('call_earning_ledgers') && !Schema::hasIndex('call_earning_ledgers', ['agency_id', 'created_at'])) {
            Schema::table('call_earning_ledgers', function (Blueprint $table) {
                $table->index(['agency_id', 'created_at'], 'call_ledger_agency_created_idx');
            });
        }

        if (Schema::hasTable('hosts') && !Schema::hasIndex('hosts', ['agency_id'])) {
            Schema::table('hosts', function (Blueprint $table) {
                $table->index('agency_id', 'hosts_agency_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('call_sessions') && Schema::hasIndex('call_sessions', 'call_sessions_agency_created_idx')) {
            Schema::table('call_sessions', function (Blueprint $table) {
                $table->dropIndex('call_sessions_agency_created_idx');
            });
        }

        if (Schema::hasTable('call_earning_ledgers') && Schema::hasIndex('call_earning_ledgers', 'call_ledger_agency_created_idx')) {
            Schema::table('call_earning_ledgers', function (Blueprint $table) {
                $table->dropIndex('call_ledger_agency_created_idx');
            });
        }

        if (Schema::hasTable('hosts') && Schema::hasIndex('hosts', 'hosts_agency_id_idx')) {
            Schema::table('hosts', function (Blueprint $table) {
                $table->dropIndex('hosts_agency_id_idx');
            });
        }
    }
};
