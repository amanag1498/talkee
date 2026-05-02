<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationRule extends Model
{
    public const RULE_TYPES = ['bad_word', 'spam', 'link', 'flooding', 'custom'];
    public const ACTIONS = ['warn', 'mute', 'kick', 'block', 'review'];
    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'rule_key',
        'rule_type',
        'pattern',
        'threshold',
        'action',
        'duration_minutes',
        'is_active',
        'severity',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'duration_minutes' => 'integer',
        'is_active' => 'boolean',
    ];
}
