<?php
// database/migrations/2025_10_02_000000_create_device_blocks_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('device_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique(); // exact device_id string from header
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable(); // null = permanent
            $table->unsignedBigInteger('created_by')->nullable(); // admin id
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('device_blocks');
    }
};
