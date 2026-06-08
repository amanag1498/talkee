<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_entry_packs', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('purchase_key');
            $table->boolean('charged')->default(false)->after('source');
            $table->unsignedInteger('price_coins')->default(0)->after('charged');
            $table->foreignId('wallet_transaction_id')->nullable()->after('price_coins')->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('granted_by_admin_id')->nullable()->after('wallet_transaction_id')->constrained('users')->nullOnDelete();
            $table->string('purchase_reference', 180)->nullable()->after('granted_by_admin_id');
            $table->text('admin_note')->nullable()->after('purchase_reference');

            $table->index(['source', 'charged']);
        });
    }

    public function down(): void
    {
        Schema::table('user_entry_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_transaction_id');
            $table->dropConstrainedForeignId('granted_by_admin_id');
            $table->dropIndex(['source', 'charged']);
            $table->dropColumn([
                'source',
                'charged',
                'price_coins',
                'purchase_reference',
                'admin_note',
            ]);
        });
    }
};
