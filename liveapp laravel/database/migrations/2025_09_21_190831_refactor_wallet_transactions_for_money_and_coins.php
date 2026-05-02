<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $t) {
            // 1) Add coins and business fields
            if (!Schema::hasColumn('wallet_transactions', 'coins')) {
                $t->unsignedBigInteger('coins')->default(0)->after('type');
            }
            if (!Schema::hasColumn('wallet_transactions', 'category')) {
                $t->string('category', 30)->default('adjustment')->after('coins'); // purchase/gift/audio_call/video_call/adjustment
            }
            if (!Schema::hasColumn('wallet_transactions', 'currency')) {
                $t->string('currency', 3)->nullable()->after('reference');
            }
            if (!Schema::hasColumn('wallet_transactions', 'transaction_id')) {
                $t->string('transaction_id', 100)->nullable()->after('currency'); // gateway transaction id
            }
            if (!Schema::hasColumn('wallet_transactions', 'gateway')) {
                $t->string('gateway', 50)->nullable()->after('transaction_id'); // razorpay/stripe/etc.
            }
            if (!Schema::hasColumn('wallet_transactions', 'counterparty_user_id')) {
                $t->foreignId('counterparty_user_id')->nullable()->after('gateway')->constrained('users')->nullOnDelete();
            }

            // 2) Clean up legacy money columns if you had them
            if (Schema::hasColumn('wallet_transactions', 'money_currency')) {
                $t->dropColumn('money_currency');
            }
            if (Schema::hasColumn('wallet_transactions', 'money_amount')) {
                $t->dropColumn('money_amount');
            }
        });

        // 3) Move old integer `amount` (coins) into new `coins` column, then convert `amount` to DECIMAL money
        //    Copy existing coin values into coins if coins is 0.
        DB::statement("UPDATE wallet_transactions SET coins = amount WHERE (coins IS NULL OR coins = 0)");

        // 4) Change `amount` column to decimal(12,2) to represent MONEY paid (nullable)
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->decimal('amount', 12, 2)->nullable()->change(); // requires doctrine/dbal
        });

        // 5) For existing rows that are not purchases, null out money fields and set category=adjustment
        DB::table('wallet_transactions')->whereNull('amount')->update(['category' => 'adjustment']);
    }

    public function down(): void
    {
        // Best-effort rollback: set coins back into amount (as integer) and drop new fields
        // NOTE: full type restoration is lossy; adjust if you truly need perfect rollback.
        // Convert `amount` back to BIGINT (coins) by moving coins into amount
        DB::statement("UPDATE wallet_transactions SET amount = COALESCE(coins, 0)");

        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('amount')->default(0)->change(); // back to integer coins
            if (Schema::hasColumn('wallet_transactions', 'counterparty_user_id')) {
                $t->dropConstrainedForeignId('counterparty_user_id');
            }
            $t->dropColumn(['coins', 'category', 'currency', 'transaction_id', 'gateway']);
        });
    }
};
