<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->enum('token_source', ['local', 'remote', 'hybrid'])
                ->default('local')
                ->after('unlock_type');
            $table->json('remote_tokens')->nullable()->after('metadata');
            $table->json('preview_tokens')->nullable()->after('remote_tokens');
            $table->unsignedInteger('min_app_version')->nullable()->after('preview_tokens');
            $table->unsignedInteger('max_app_version')->nullable()->after('min_app_version');
        });
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropColumn([
                'token_source',
                'remote_tokens',
                'preview_tokens',
                'min_app_version',
                'max_app_version',
            ]);
        });
    }
};
