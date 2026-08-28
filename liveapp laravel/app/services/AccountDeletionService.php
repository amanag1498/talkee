<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AccountDeletionService
{
    /**
     * Permanently close the app account while retaining only anonymized rows
     * needed by financial, fraud-prevention, moderation, and payout ledgers.
     */
    public function delete(User $user): void
    {
        $mediaPaths = [];

        DB::transaction(function () use ($user, &$mediaPaths): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $originalEmail = (string) $locked->getRawOriginal('email');
            $this->collectLocalPath($mediaPaths, $locked->getRawOriginal('avatar_url'));

            $host = Host::query()
                ->with('photos')
                ->where('user_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($host) {
                foreach ($host->photos as $photo) {
                    $this->collectLocalPath($mediaPaths, $photo->path);
                }
                $host->photos()->delete();
                $host->forceFill([
                    'stage_name' => 'Deleted Host',
                    'contact_phone' => null,
                    'country' => null,
                    'city' => null,
                    'bio' => null,
                    'kyc' => null,
                    'is_blocked' => true,
                ])->save();
            }

            $agency = Agency::query()
                ->where('owner_user_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($agency) {
                $agency->forceFill([
                    'name' => 'Deleted Agency',
                    'legal_name' => null,
                    'contact_email' => null,
                    'contact_phone' => null,
                    'notes' => null,
                    'is_blocked' => true,
                ])->save();
            }

            $this->deleteWhere('agency_requests', 'user_id', $locked->id);
            $this->deleteWhere('host_requests', 'user_id', $locked->id);
            $this->nullWhere('agency_requests', 'reviewed_by', $locked->id);
            $this->nullWhere('host_requests', 'reviewed_by', $locked->id);
            $this->deleteWhere('sessions', 'user_id', $locked->id);
            if ($originalEmail !== '' && Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
            }

            $locked->tokens()->delete();
            if (method_exists($locked, 'syncRoles')) {
                $locked->syncRoles([]);
            }

            $locked->forceFill([
                'name' => 'Deleted User',
                'email' => sprintf(
                    'deleted-%d-%s@users.talkieo.invalid',
                    $locked->id,
                    Str::lower(Str::random(12)),
                ),
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'firebase_uid' => null,
                'avatar_url' => null,
                'provider' => 'deleted',
                'device_id' => null,
                'is_blocked' => true,
                'level_id' => null,
                'legacy_lifetime_spend_coins' => 0,
                'lifetime_spend_coins' => 0,
                'last_login_at' => null,
                'last_login_date' => null,
                'current_login_streak_days' => 0,
                'max_login_streak_days' => 0,
                'referral_code' => null,
                'referred_by_user_id' => null,
            ])->save();
        });

        foreach (array_unique($mediaPaths) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @param array<int, string> $paths */
    private function collectLocalPath(array &$paths, mixed $value): void
    {
        $path = trim((string) $value);
        if ($path === '' || Str::startsWith($path, ['http://', 'https://'])) {
            return;
        }
        $path = ltrim(Str::after($path, '/storage/'), '/');
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    private function deleteWhere(string $table, string $column, int $userId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->where($column, $userId)->delete();
        }
    }

    private function nullWhere(string $table, string $column, int $userId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->where($column, $userId)->update([$column => null]);
        }
    }
}
