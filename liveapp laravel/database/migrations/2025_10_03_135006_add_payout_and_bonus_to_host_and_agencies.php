<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // HOSTS
        Schema::table('hosts', function (Blueprint $t) {
            if (!Schema::hasColumn('hosts', 'payout_percentage')) {
                $t->decimal('payout_percentage', 5, 2)->default(0.00)->after('bio'); // 0.00 - 100.00
            }
            if (!Schema::hasColumn('hosts', 'weekly_bonus')) {
                $t->unsignedBigInteger('weekly_bonus')->default(0)->after('payout_percentage'); // store in coins or minor currency
            }
        });

        // AGENCIES
        Schema::table('agencies', function (Blueprint $t) {
            if (!Schema::hasColumn('agencies', 'payout_percentage')) {
                $t->decimal('payout_percentage', 5, 2)->default(0.00)->after('is_blocked');
            }
            if (!Schema::hasColumn('agencies', 'weekly_bonus')) {
                $t->unsignedBigInteger('weekly_bonus')->default(0)->after('payout_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $t) {
            if (Schema::hasColumn('hosts', 'payout_percentage')) $t->dropColumn('payout_percentage');
            if (Schema::hasColumn('hosts', 'weekly_bonus')) $t->dropColumn('weekly_bonus');
        });

        Schema::table('agencies', function (Blueprint $t) {
            if (Schema::hasColumn('agencies', 'payout_percentage')) $t->dropColumn('payout_percentage');
            if (Schema::hasColumn('agencies', 'weekly_bonus')) $t->dropColumn('weekly_bonus');
        });
    }
};
