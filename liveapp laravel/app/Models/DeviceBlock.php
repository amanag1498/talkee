<?php
// app/Models/DeviceBlock.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DeviceBlock extends Model
{
    protected $fillable = ['device_id','reason','expires_at','created_by'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public static function isBlocked(?string $deviceId): bool
    {
        if (!$deviceId) return false;
        $rec = static::where('device_id', $deviceId)->first();
        if (!$rec) return false;
        if (is_null($rec->expires_at)) return true;
        return Carbon::now()->lt($rec->expires_at);
    }
}
