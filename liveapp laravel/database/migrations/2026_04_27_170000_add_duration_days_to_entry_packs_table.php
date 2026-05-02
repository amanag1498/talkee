<?php

use App\Models\EntryPack;
use App\Models\UserEntryPack;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_packs', function (Blueprint $table) {
            $table->unsignedInteger('duration_days')->default(30)->after('duration_ms');
        });

        EntryPack::query()
            ->whereNull('duration_days')
            ->update(['duration_days' => 30]);

        UserEntryPack::query()
            ->with('entryPack:id,duration_days')
            ->whereNull('expires_at')
            ->whereNotNull('purchased_at')
            ->get()
            ->each(function (UserEntryPack $userPack): void {
                $days = max(1, (int) ($userPack->entryPack?->duration_days ?? 30));
                $userPack->forceFill([
                    'expires_at' => $userPack->purchased_at?->copy()->addDays($days),
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::table('entry_packs', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });
    }
};
