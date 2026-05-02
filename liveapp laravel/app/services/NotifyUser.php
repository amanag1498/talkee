<?php
// app/Services/NotifyUser.php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Models\DevicePushToken;
use App\Support\FirebaseAdminConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Kreait\Firebase\Factory;

class NotifyUser
{
    public static function emitRealtime(int $userId, array $payload): void
    {
        self::emitRealtimeMany(collect([$userId]), $payload);
    }

    public static function emitRealtimeMany($audience, array $payload): void
    {
        $userIds = self::resolveAudience($audience);
        if ($userIds->isEmpty()) {
            return;
        }

        foreach ($userIds as $uid) {
            self::publishRealtime((int) $uid, $payload);
        }
    }

    public static function send(int $userId, array $payload, array $options = []): void
    {
        self::sendMany(collect([$userId]), $payload, $options);
    }

    public static function sendMany($audience, array $payload, array $options = []): void
    {
        $push    = $options['push']    ?? true;
        $persist = $options['persist'] ?? true;

        $userIds = self::resolveAudience($audience);
        if ($userIds->isEmpty()) return;

        if ($persist) {
            foreach ($userIds as $uid) {
                $notification = UserNotification::create([
                    'user_id' => $uid,
                    'type'    => $payload['type']  ?? null,
                    'title'   => $payload['title'] ?? 'Notification',
                    'body'    => $payload['body']  ?? null,
                    'meta'    => $payload['meta']  ?? null,
                ]);

                self::pruneUserNotifications($uid, 10);

                DB::afterCommit(function () use ($uid, $payload, $notification): void {
                    try {
                        self::publishRealtime($uid, [
                            'id' => $notification->id,
                            ...$payload,
                        ]);
                    } catch (\Throwable $e) {
                    }
                });
            }
        }

        if ($push) {
            self::pushToUsers($userIds, $payload);
        }
    }

    protected static function pruneUserNotifications(int $userId, int $keep = 10): void
    {
        if ($keep < 1) $keep = 1;

        // Find the 10th newest id (LIMIT 1 OFFSET 9) — valid in MariaDB
        $cutoffId = UserNotification::where('user_id', $userId)
            ->orderByDesc('id')
            ->skip($keep - 1)  // 9 for keep=10
            ->value('id');     // compiles to LIMIT 1 OFFSET N

        if ($cutoffId) {
            // Delete anything older than the cutoff
            UserNotification::where('user_id', $userId)
                ->where('id', '<', $cutoffId)
                ->delete();
        }
    }

    protected static function resolveAudience($audience): Collection
    {
        if ($audience instanceof Collection) return $audience->filter()->unique()->values();
        if (is_array($audience) && isset($audience['role'])) {
            return User::role($audience['role'])->pluck('id');
        }
        if ($audience === 'all') return User::pluck('id');
        if (is_numeric($audience)) return collect([(int)$audience]);
        return collect();
    }

    protected static function pushToUsers(Collection $userIds, array $payload): void
    {
        $tokens = DevicePushToken::whereIn('user_id', $userIds)->pluck('token')->all();
        if (!$tokens) return;

        try {
            $serviceAccountPath = FirebaseAdminConfig::serviceAccountPath();
            $projectId          = FirebaseAdminConfig::projectId();
            $messaging = (new Factory())
                ->withServiceAccount($serviceAccountPath)
                ->withProjectId($projectId)
                ->createMessaging();

            $base = [
    'notification' => [
        'title' => $payload['title'] ?? 'Notification',
        'body'  => $payload['body']  ?? '',
    ],
    'data' => [
        'type'    => (string)($payload['type'] ?? ''),
        'screen'  => (string)($payload['screen'] ?? 'notifications'), // e.g. 'room'
        'room_id' => (string)($payload['room_id'] ?? ''),
        'meta'    => json_encode($payload['meta'] ?? []),
    ],
];

            // send in chunks (<= 500)
            $chunk = 500;
            for ($i = 0; $i < count($tokens); $i += $chunk) {
                $slice = array_slice($tokens, $i, $chunk);
                $messaging->sendMulticast($base, $slice);
            }
        } catch (\Throwable $e) {
            // swallow for admin UX
        }
    }

    protected static function publishRealtime(int $userId, array $payload): void
    {
        Redis::publish('users:notify', json_encode([
            'user_id' => $userId,
            'id' => $payload['id'] ?? null,
            'type' => $payload['type'] ?? null,
            'title' => $payload['title'] ?? 'Notification',
            'body' => $payload['body'] ?? null,
            'meta' => $payload['meta'] ?? null,
            'screen' => $payload['screen'] ?? 'notifications',
            'at' => now()->toIso8601String(),
        ]));
    }
}
