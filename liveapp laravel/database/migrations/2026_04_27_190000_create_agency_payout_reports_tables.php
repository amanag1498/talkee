<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_payout_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->unsignedBigInteger('gross_earnings')->default(0);
            $table->unsignedBigInteger('platform_commission')->default(0);
            $table->unsignedBigInteger('agency_commission')->default(0);
            $table->unsignedBigInteger('host_share')->default(0);
            $table->unsignedBigInteger('deductions')->default(0);
            $table->unsignedBigInteger('final_payable')->default(0);
            $table->string('status')->default('generated');
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('admin_remarks')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'period_start', 'period_end'], 'agency_payout_reports_unique_period');
            $table->index(['status', 'period_start']);
        });

        Schema::create('agency_payout_report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_payout_report_id')->constrained('agency_payout_reports')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('call_earnings')->default(0);
            $table->unsignedBigInteger('gift_earnings')->default(0);
            $table->unsignedBigInteger('live_room_earnings')->default(0);
            $table->unsignedBigInteger('pk_earnings')->default(0);
            $table->unsignedBigInteger('gross_earnings')->default(0);
            $table->unsignedBigInteger('agency_commission')->default(0);
            $table->unsignedBigInteger('host_share')->default(0);
            $table->unsignedBigInteger('final_payable')->default(0);
            $table->timestamps();

            $table->unique(['agency_payout_report_id', 'host_id'], 'agency_payout_report_items_unique_host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_payout_report_items');
        Schema::dropIfExists('agency_payout_reports');
    }
};
