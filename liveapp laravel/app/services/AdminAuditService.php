<?php

namespace App\Services;

use App\Models\AdminActionAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AdminAuditService
{
    public function log(
        string $area,
        string $action,
        ?User $admin = null,
        ?User $targetUser = null,
        ?Model $entity = null,
        mixed $before = null,
        mixed $after = null,
        ?string $reason = null,
        array $meta = [],
    ): AdminActionAudit {
        return AdminActionAudit::query()->create([
            'admin_user_id' => $admin?->id,
            'target_user_id' => $targetUser?->id,
            'area' => $area,
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'reason' => $reason,
            'before_state' => $this->normalize($before),
            'after_state' => $this->normalize($after),
            'meta' => $meta ?: null,
        ]);
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $value->toArray();
        }

        if (is_object($value)) {
            return method_exists($value, 'toArray')
                ? $value->toArray()
                : json_decode(json_encode($value), true);
        }

        return $value;
    }
}
