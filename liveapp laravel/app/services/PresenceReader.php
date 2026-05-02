<?php
// app/Services/PresenceReader.php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use App\Models\User;

class PresenceReader
{
    public function count(): int
    {
        return (int) Redis::connection('presence')->scard('presence:online');
    }

    /**
     * @return array<int, array{user_id:int, ttl_ms:int|null, user?:array}>
     */
    public function list(bool $includeUsers = true): array
    {
        $conn = Redis::connection('presence');

        $ids = $conn->smembers('presence:online') ?: [];
        $ids = array_values(array_filter(array_map('intval', $ids)));

        $out = [];
        foreach ($ids as $uid) {
            $ttlMs = $conn->pttl("presence:hb:$uid"); // -1 no expire, -2 no key
            $out[] = [
                'user_id' => $uid,
                'ttl_ms'  => is_numeric($ttlMs) && $ttlMs > 0 ? (int) $ttlMs : null,
            ];
        }

        if ($includeUsers && $ids) {
            $users = User::query()
                ->whereIn('id', $ids)
                ->get(['id','name','email'])
                ->keyBy('id');
            foreach ($out as &$row) {
                if (isset($users[$row['user_id']])) {
                    $u = $users[$row['user_id']];
                    $row['user'] = ['id'=>$u->id,'name'=>$u->name,'email'=>$u->email];
                }
            }
        }

        usort($out, fn($a,$b) => ($a['ttl_ms'] ?? PHP_INT_MAX) <=> ($b['ttl_ms'] ?? PHP_INT_MAX));
        return $out;
    }
}
