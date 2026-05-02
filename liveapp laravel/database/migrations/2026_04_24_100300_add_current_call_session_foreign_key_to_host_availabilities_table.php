<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('host_availabilities', function (Blueprint $table) {
            $table->foreign('current_call_session_id')
                ->references('id')
                ->on('call_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('host_availabilities', function (Blueprint $table) {
            $table->dropForeign(['current_call_session_id']);
        });
    }
};
