<?php

namespace App\Services;

use App\Models\HostAvailability;
use App\Models\HostFollower;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HostNotificationService
{
    private const COOLDOWN_MINUTES = 15;

    public function handleAvailabilityTransition(User $hostUser, HostAvailability $before, HostAvailability $after): void
    {
        $host = $hostUser->host;
        if (!$host || $host->is_blocked || $hostUser->is_blocked) {
            return;
        }

        $wasOnline = $this->isOnline($before);
        $isOnline = $this->isOnline($after);
        $wasAvailable = $this->isAvailable($before);
        $isAvailable = $this->isAvailable($after);

        if (!$wasOnline && $isOnline) {
            $this->notifyFollowers($host->id, 'host_online', $host->stage_name ?: $hostUser->name);
        }

        if (!$wasAvailable && $isAvailable) {
            $this->notifyFollowers($host->id, 'host_available', $host->stage_name ?: $hostUser->name);
        }
    }

    private function notifyFollowers(int $hostId, string $type, string $hostName): void
    {
        $preferenceColumn = $type === 'host_online' ? 'notify_when_online' : 'notify_when_available';
        $timestampColumn = $type === 'host_online' ? 'last_online_notified_at' : 'last_available_notified_at';
        $cooldownCutoff = now()->subMinutes(self::COOLDOWN_MINUTES);

        $followers = HostFollower::query()
            ->with('user')
            ->where('host_id', $hostId)
            ->where($preferenceColumn, true)
            ->where(function ($query) use ($timestampColumn, $cooldownCutoff) {
                $query->whereNull($timestampColumn)->orWhere($timestampColumn, '<=', $cooldownCutoff);
            })
            ->get();

        foreach ($followers as $follow) {
            DB::transaction(function () use ($follow, $timestampColumn, $type, $hostId, $hostName): void {
                $locked = HostFollower::query()->lockForUpdate()->find($follow->id);
                if (!$locked) {
                    return;
                }

                $lastSent = $locked->{$timestampColumn};
                if ($lastSent && $lastSent->gt(now()->subMinutes(self::COOLDOWN_MINUTES))) {
                    return;
                }

                $locked->{$timestampColumn} = now();
                $locked->save();

                $body = $type === 'host_online'
                    ? "{$hostName} is online now."
                    : "{$hostName} is now available for calls.";

                NotifyUser::send((int) $locked->user_id, [
                    'type' => $type,
                    'title' => $hostName,
                    'body' => $body,
                    'meta' => [
                        'host_id' => $hostId,
                        'notification_type' => $type,
                    ],
                    'screen' => 'notifications',
                ], [
                    'push' => true,
                    'persist' => true,
                ]);
            });
        }
    }

    private function isOnline(HostAvailability $availability): bool
    {
        return $availability->manual_status === 'online'
            && $availability->socket_status === 'online';
    }

    private function isAvailable(HostAvailability $availability): bool
    {
        return $this->isOnline($availability)
            && $availability->call_status === 'available';
    }
}
