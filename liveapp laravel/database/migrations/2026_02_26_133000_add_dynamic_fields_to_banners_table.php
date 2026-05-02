<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('placement', 40)->default('home')->index()->after('target_url');
            $table->string('action_type', 40)->default('none')->after('placement');
            $table->string('action_value', 2048)->nullable()->after('action_type');
            $table->string('button_text', 60)->nullable()->after('action_value');
            $table->json('platforms')->nullable()->after('button_text');
            $table->json('target_roles')->nullable()->after('platforms');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'placement',
                'action_type',
                'action_value',
                'button_text',
                'platforms',
                'target_roles',
            ]);
        });
    }
};

