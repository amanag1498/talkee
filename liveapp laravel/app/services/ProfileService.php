<?php

namespace App\Services;

use App\Models\UserSubscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileService
{
    public function __construct(
        private UserLevelService $levels,
        private HostFollowService $follows,
        private ThemeUnlockService $themes,
        private ProfileFrameService $frames,
    )
    {
    }

    public function payload(
        User $user,
        ?User $viewer = null,
        bool $public = false,
        ?int $appVersionCode = null,
    ): array
    {
        $user->loadMissing(['host.agency.owner', 'wallet', 'level']);
        $host = $user->host;
        $agency = $host?->agency;
        $level = $this->levels->profileProgress($user);
        $followCounts = $this->follows->profileCounts($user);
        $viewer = $viewer?->withoutRelations();
        $followState = null;
        if ($viewer && $host && (int) $viewer->id !== (int) $user->id) {
            $followState = $this->follows->followState($viewer, $host);
        }
        $isVip = $this->isVip($user);
        $displayName = $host?->stage_name ?: $user->name;
        $hostGoalOverrides = $host ? [
            'followers' => $this->parseIntegerList($host->goal_followers),
            'weekly_live_minutes' => $this->parseIntegerList($host->goal_weekly_live_minutes),
            'weekly_gifted_coins' => $this->parseIntegerList($host->goal_weekly_gifted_coins),
        ] : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'display_name' => $displayName,
            'email' => $public ? '' : $user->email,
            'is_blocked' => (bool) $user->is_blocked,
            'avatar_url' => $user->avatar_url,
            'profile_frame' => $this->frames->equippedFramePayload($user),
            'joined_at' => optional($user->created_at)->toIso8601String(),
            'roles' => $user->getRoleNames()->values()->all(),
            'is_vip' => $isVip,
            'active_theme_key' => $this->themes->activeThemeKeyFor(
                $user,
                true,
                $appVersionCode,
            ),
            'can_go_live' => !($user->is_blocked || $host?->is_blocked) && (
                $user->hasAnyRole(['host', 'admin']) || $user->can('go live')
            ),
            'wallet_balance' => $public ? 0 : (int) ($user->wallet?->balance ?? 0),
            'level_id' => $level['level_id'],
            'level' => $level['level'],
            'level_title' => $level['level_title'],
            'badge_icon' => $level['badge_icon'],
            'badge_color' => $level['badge_color'],
            'lifetime_spend_coins' => $level['lifetime_spend_coins'],
            'next_level' => $level['next_level'],
            'next_level_title' => $level['next_level_title'],
            'next_level_required_spend' => $level['next_level_required_spend'],
            'remaining_spend_to_next_level' => $level['remaining_spend_to_next_level'],
            'progress_percent' => $level['progress_percent'],
            'following_count' => $followCounts['following_count'],
            'follower_count' => $followCounts['follower_count'],
            'followers_count' => $followCounts['follower_count'],
            'host_id' => $host?->id,
            'is_following' => $followState['is_following'] ?? false,
            'notify_when_online' => $followState['notify_when_online'] ?? true,
            'notify_when_available' => $followState['notify_when_available'] ?? true,
            'host_profile' => $host ? [
                'host_id' => $host->id,
                'stage_name' => $host->stage_name,
                'contact_phone' => $host->contact_phone,
                'country' => $host->country,
                'city' => $host->city,
                'bio' => $host->bio,
                'agency_id' => $host->agency_id,
                'is_blocked' => (bool) $host->is_blocked,
                'video_rooms_enabled' => (bool) $host->video_rooms_enabled,
                'audio_rooms_enabled' => (bool) $host->audio_rooms_enabled,
                'video_calls_enabled' => (bool) $host->video_calls_enabled,
                'audio_calls_enabled' => (bool) $host->audio_calls_enabled,
                'goal_overrides' => $hostGoalOverrides,
                'agency' => $agency ? [
                    'id' => $agency->id,
                    'name' => $agency->name,
                    'legal_name' => $agency->legal_name,
                    'contact_email' => $agency->contact_email,
                    'contact_phone' => $agency->contact_phone,
                    'owner_user_id' => $agency->owner_user_id,
                    'owner_name' => $agency->owner?->name,
                    'is_blocked' => (bool) $agency->is_blocked,
                ] : null,
            ] : null,
            'status' => [
                'is_host' => $user->hasRole('host'),
                'is_agency' => $user->hasRole('agency'),
                'is_admin' => $user->hasRole('admin'),
                'agency_attached' => (bool) $host?->agency_id,
                'host_blocked' => (bool) $host?->is_blocked,
            ],
        ];
    }

    private function isVip(User $user): bool
    {
        $roleNames = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(fn ($role) => strtolower((string) $role))->values()->all()
            : [];
        $activeSub = UserSubscription::query()
            ->with('plan:id,name')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()->copy()->addSeconds(5));
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now()->copy()->subSeconds(5));
            })
            ->latest('ends_at')
            ->latest('id')
            ->first();
        $planName = strtolower((string) ($activeSub?->plan?->name ?? ''));

        return in_array('vip', $roleNames, true)
            || in_array('premium', $roleNames, true)
            || str_contains($planName, 'vip')
            || str_contains($planName, 'premium')
            || str_contains($planName, 'elite')
            || str_contains($planName, 'platinum')
            || str_contains($planName, 'gold');
    }

    private function parseIntegerList(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        return collect(preg_split('/\s*,\s*/', $value) ?: [])
            ->map(fn ($part) => (int) $part)
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function update(User $user, array $data): User
    {
        $user->forceFill([
            'name' => $data['name'] ?? $user->name,
        ])->save();

        if ($user->hasRole('host') && $user->host) {
            $user->host->update([
                'stage_name' => $data['stage_name'] ?? $user->host->stage_name,
                'contact_phone' => $data['contact_phone'] ?? $user->host->contact_phone,
                'country' => $data['country'] ?? $user->host->country,
                'city' => $data['city'] ?? $user->host->city,
                'bio' => $data['bio'] ?? $user->host->bio,
            ]);
        }

        return $user->fresh(['host', 'wallet']);
    }

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $previous = (string) $user->getRawOriginal('avatar_url');
        $previousLocalPath = $this->normalizeLocalAvatarPath($previous);
        $extension = strtolower((string) ($file->extension() ?: 'jpg'));
        $filename = sprintf(
            'avatar_%s_%s.%s',
            $user->id,
            Str::uuid()->toString(),
            $extension
        );
        $stored = $file->storeAs('avatars', $filename, 'public');

        $user->forceFill([
            'avatar_url' => $stored,
        ])->save();

        if (
            $previousLocalPath &&
            $previousLocalPath !== $stored &&
            Storage::disk('public')->exists($previousLocalPath)
        ) {
            Storage::disk('public')->delete($previousLocalPath);
        }

        return $user->fresh(['host', 'wallet']);
    }

    private function normalizeLocalAvatarPath(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        if (Str::startsWith($value, '/storage/avatars/')) {
            return ltrim(Str::after($value, '/storage/'), '/');
        }

        if (Str::startsWith($value, 'avatars/')) {
            return $value;
        }

        return null;
    }
}
