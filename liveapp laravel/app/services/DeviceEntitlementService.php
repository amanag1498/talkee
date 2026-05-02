<?php

namespace App\Services;

use App\Models\DeviceEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceEntitlementService
{
    /**
     * Reserve the device for a signup gift exactly once.
     * Throws ValidationException if already claimed anywhere.
     */
    public function claimSignupGiftOrFail(string $deviceId, int $userId, array $meta = []): DeviceEntitlement
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            throw ValidationException::withMessages(['device_id' => 'Device ID is required for signup gift.']);
        }

        try {
            return DB::transaction(function () use ($deviceId, $userId, $meta) {
                $entitlement = new DeviceEntitlement([
                    'device_id'        => $deviceId,
                    'user_id'          => $userId,
                    'entitlement_type' => 'signup_gift',
                    'granted_at'       => now(),
                    'meta'             => $meta,
                ]);
                $entitlement->save(); // unique(device_id) enforces one per device
                return $entitlement;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique violation => already used on some account
            if (str_contains($e->getMessage(), 'device_entitlements_device_id_unique')) {
                throw ValidationException::withMessages([
                    'device_id' => 'A free subscription has already been claimed on this device.',
                ]);
            }
            throw $e;
        }
    }

    /** Attach the resulting subscription to the entitlement (audit) */
    public function attachSubscription(int $entitlementId, int $subscriptionId): void
    {
        DeviceEntitlement::whereKey($entitlementId)->update(['subscription_id' => $subscriptionId]);
    }
}
