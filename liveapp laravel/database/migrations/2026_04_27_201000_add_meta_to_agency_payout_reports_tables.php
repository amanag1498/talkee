<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_payout_reports', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('admin_remarks');
        });

        Schema::table('agency_payout_report_items', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('final_payable');
        });
    }

    public function down(): void
    {
        Schema::table('agency_payout_report_items', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        Schema::table('agency_payout_reports', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
