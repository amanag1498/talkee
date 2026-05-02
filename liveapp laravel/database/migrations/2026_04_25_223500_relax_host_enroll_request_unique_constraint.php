<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('host_enroll_requests', function (Blueprint $table) {
            $table->index('host_user_id', 'host_enroll_requests_host_user_id_idx');
            $table->index('agency_id', 'host_enroll_requests_agency_id_idx');
            $table->dropUnique('host_enroll_requests_host_user_id_agency_id_status_unique');
            $table->index(
                ['host_user_id', 'agency_id', 'status'],
                'host_enroll_requests_host_agency_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('host_enroll_requests', function (Blueprint $table) {
            $table->dropIndex('host_enroll_requests_host_agency_status_idx');
            $table->dropIndex('host_enroll_requests_host_user_id_idx');
            $table->dropIndex('host_enroll_requests_agency_id_idx');
            $table->unique(
                ['host_user_id', 'agency_id', 'status'],
                'host_enroll_requests_host_user_id_agency_id_status_unique'
            );
        });
    }
};
