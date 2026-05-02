<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_blocked')->default(false)->index();
        });
        Schema::table('hosts', function (Blueprint $t) {
            $t->boolean('is_blocked')->default(false)->index();
        });
        Schema::table('agencies', function (Blueprint $t) {
            $t->boolean('is_blocked')->default(false)->index();
        });
    }
    public function down(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('is_blocked'));
        Schema::table('hosts', fn(Blueprint $t) => $t->dropColumn('is_blocked'));
        Schema::table('agencies', fn(Blueprint $t) => $t->dropColumn('is_blocked'));
    }
};
