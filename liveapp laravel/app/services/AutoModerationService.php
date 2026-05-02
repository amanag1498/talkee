<?php

namespace App\Services;

use App\Models\LiveRoom;
use App\Models\ModerationRule;
use App\Models\UserReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AutoModerationService
{
    public function __construct(private ModerationService $moderation)
    {
    }

    public function evaluateChatMessage(LiveRoom $room, User $sender, string $message): array
    {
        $sanitized = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? '');
        if ($sanitized === '') {
            return [
                'allow' => false,
                'action' => 'warn',
                'message' => 'Message cannot be empty.',
            ];
        }

        $rules = ModerationRule::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();

        foreach ($rules as $rule) {
            $matched = match ($rule->rule_type) {
                'bad_word', 'custom' => $this->matchesPattern($sanitized, $rule->pattern),
                'link' => $this->containsLink($sanitized),
                'spam', 'flooding' => $this->isSpam($room, $sender, $sanitized, $rule),
                default => false,
            };

            if (!$matched) {
                continue;
            }

            return $this->applyRule($rule, $room, $sender, $sanitized);
        }

        return [
            'allow' => true,
            'message' => $sanitized,
        ];
    }

    public function clearChatState(LiveRoom $room, User $sender): void
    {
        $baseKey = sprintf('moderation:chat:%s:%d', $room->room_id, $sender->id);
        Cache::forget($baseKey);
    }

    private function matchesPattern(string $message, ?string $pattern): bool
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            return false;
        }

        $escaped = preg_quote(Str::lower($pattern), '/');
        return (bool) preg_match('/'.$escaped.'/i', Str::lower($message));
    }

    private function containsLink(string $message): bool
    {
        return (bool) preg_match('/https?:\/\/|www\.|[a-z0-9\-_]+\.[a-z]{2,}/i', $message);
    }

    private function isSpam(LiveRoom $room, User $sender, string $message, ModerationRule $rule): bool
    {
        $windowSeconds = max(5, ($rule->duration_minutes ?? 1) * 60);
        $threshold = max(2, (int) ($rule->threshold ?? 3));
        $baseKey = sprintf('moderation:chat:%s:%d', $room->room_id, $sender->id);
        $now = now()->getTimestamp();

        $timeline = Cache::get($baseKey, []);
        $timeline[] = ['t' => $now, 'm' => Str::lower($message)];
        $timeline = array_values(array_filter($timeline, fn ($row) => ($now - (int) ($row['t'] ?? 0)) <= $windowSeconds));
        Cache::put($baseKey, $timeline, now()->addSeconds($windowSeconds));

        $sameCount = count(array_filter($timeline, fn ($row) => ($row['m'] ?? '') === Str::lower($message)));

        if ($rule->rule_type === 'spam') {
            return $sameCount >= $threshold;
        }

        return count($timeline) >= $threshold;
    }

    private function applyRule(ModerationRule $rule, LiveRoom $room, User $sender, string $message): array
    {
        $hostUserId = $this->moderation->hostUserIdForRoom($room);
        $reason = sprintf('Auto moderation: %s', $rule->rule_key);

        return match ($rule->action) {
            'warn', 'mute' => $this->warnAction($rule, $room, $sender, $reason, $message),
            'review' => $this->reviewAction($rule, $room, $sender, $reason, $message),
            'kick' => $this->kickAction($rule, $room, $sender, $reason),
            'block' => $this->blockAction($rule, $room, $sender, $hostUserId, $reason),
            default => ['allow' => false, 'action' => 'warn', 'message' => 'Message blocked.'],
        };
    }

    private function warnAction(ModerationRule $rule, LiveRoom $room, User $sender, string $reason, string $message): array
    {
        $this->moderation->recordAction(
            actionType: 'auto_warn',
            actor: null,
            target: $sender,
            hostUserId: $this->moderation->hostUserIdForRoom($room),
            roomId: $room->room_id,
            roomType: $room->room_type,
            reason: $reason,
            metadata: ['rule_key' => $rule->rule_key, 'message' => $message],
        );
        $this->clearChatState($room, $sender);

        return [
            'allow' => false,
            'action' => 'warn',
            'message' => 'Your message violates room moderation rules.',
            'system_message' => null,
        ];
    }

    private function reviewAction(ModerationRule $rule, LiveRoom $room, User $sender, string $reason, string $message): array
    {
        $hostUserId = $this->moderation->hostUserIdForRoom($room);
        UserReport::query()->create([
            'reporter_user_id' => $hostUserId ?: $sender->id,
            'reported_user_id' => $sender->id,
            'host_user_id' => $hostUserId,
            'room_id' => $room->room_id,
            'room_type' => $room->room_type,
            'reason_type' => 'auto_moderation',
            'description' => $reason.' | '.$message,
            'status' => 'pending',
        ]);

        $this->moderation->recordAction(
            actionType: 'auto_review',
            actor: null,
            target: $sender,
            hostUserId: $hostUserId,
            roomId: $room->room_id,
            roomType: $room->room_type,
            reason: $reason,
            metadata: ['rule_key' => $rule->rule_key, 'message' => $message],
        );
        $this->clearChatState($room, $sender);

        return [
            'allow' => false,
            'action' => 'review',
            'message' => 'Message sent to moderation review.',
        ];
    }

    private function kickAction(ModerationRule $rule, LiveRoom $room, User $sender, string $reason): array
    {
        $hostUser = $room->host?->user;
        if ($hostUser) {
            $this->moderation->kickUserFromRoom($room, $sender, $hostUser, $reason, true);
            $this->moderation->recordAction(
                actionType: 'auto_kick',
                actor: null,
                target: $sender,
                hostUserId: $this->moderation->hostUserIdForRoom($room),
                roomId: $room->room_id,
                roomType: $room->room_type,
                reason: $reason,
                metadata: ['rule_key' => $rule->rule_key],
            );
        } else {
            $this->moderation->recordAction(
                actionType: 'auto_kick',
                actor: null,
                target: $sender,
                hostUserId: $this->moderation->hostUserIdForRoom($room),
                roomId: $room->room_id,
                roomType: $room->room_type,
                reason: $reason,
                metadata: ['rule_key' => $rule->rule_key],
            );
        }
        $this->clearChatState($room, $sender);

        return [
            'allow' => false,
            'action' => 'kick',
            'message' => 'You were removed from this room.',
        ];
    }

    private function blockAction(ModerationRule $rule, LiveRoom $room, User $sender, ?int $hostUserId, string $reason): array
    {
        $hostUser = $room->host?->user;
        if ($hostUser && $hostUserId) {
            $this->moderation->blockUserForHost($hostUser, $sender, $hostUser, $reason, $room, $room->room_type, true);
            $this->moderation->recordAction(
                actionType: 'auto_block',
                actor: null,
                target: $sender,
                hostUserId: $hostUserId,
                roomId: $room->room_id,
                roomType: $room->room_type,
                reason: $reason,
                metadata: ['rule_key' => $rule->rule_key],
            );
        }
        $this->clearChatState($room, $sender);

        return [
            'allow' => false,
            'action' => 'block',
            'message' => 'You were blocked by this host.',
        ];
    }
}
