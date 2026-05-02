<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            // fast WHERE user_id ORDER BY id DESC
            $table->index(['user_id', 'id'], 'user_id_id_idx');

            // fast unread count
            $table->index(['user_id', 'read_at'], 'user_id_read_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            // drop by index names
            $table->dropIndex('user_id_id_idx');
            $table->dropIndex('user_id_read_idx');
        });
    }
};
