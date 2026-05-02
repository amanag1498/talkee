<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('call_earning_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained('call_sessions')->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained('hosts')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->unsignedBigInteger('total_coins');
            $table->unsignedBigInteger('host_earning');
            $table->unsignedBigInteger('agency_earning');
            $table->unsignedBigInteger('platform_earning');
            $table->unsignedInteger('duration_seconds');
            $table->unsignedInteger('billable_minutes');
            $table->timestamps();

            $table->unique('call_session_id');
            $table->index(['host_id', 'created_at'], 'call_ledger_host_created_idx');
            $table->index(['agency_id', 'created_at'], 'call_ledger_agency_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_earning_ledgers');
    }
};
