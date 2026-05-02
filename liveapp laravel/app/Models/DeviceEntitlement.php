<?php



namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEntitlement extends Model
{
    protected $fillable = [
        'device_id', 'user_id', 'subscription_id', 'entitlement_type', 'meta', 'granted_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'granted_at' => 'datetime',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function subscription() {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }
}

