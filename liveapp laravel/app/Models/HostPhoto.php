<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostPhoto extends Model
{
    protected $fillable = ['host_id', 'path', 'sort'];

    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }
}
