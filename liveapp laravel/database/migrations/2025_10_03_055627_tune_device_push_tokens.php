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
        Schema::table('device_push_tokens', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->unique(['user_id','token'], 'uniq_user_token');
    $t->index('token', 'token_idx');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
