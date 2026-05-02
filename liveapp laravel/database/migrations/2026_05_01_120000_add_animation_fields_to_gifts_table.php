<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('gifts', function (Blueprint $table) {
            $table->string('gift_type', 20)->nullable()->after('gift_url');
            $table->string('animation_tier', 20)->nullable()->after('gift_type');
            $table->unsignedInteger('animation_duration_ms')->nullable()->after('animation_tier');
        });
    }

    public function down(): void
    {
        Schema::table('gifts', function (Blueprint $table) {
            $table->dropColumn([
                'gift_type',
                'animation_tier',
                'animation_duration_ms',
            ]);
        });
    }
};
