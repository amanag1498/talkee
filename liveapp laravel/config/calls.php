<?php

return [
    'coin_rate_per_minute' => (int) env('CALLS_COIN_RATE_PER_MINUTE', 20),
    'audio_coin_rate_per_minute' => (int) env(
        'CALLS_AUDIO_COIN_RATE_PER_MINUTE',
        (int) env('CALLS_COIN_RATE_PER_MINUTE', 20)
    ),
    'video_coin_rate_per_minute' => (int) env(
        'CALLS_VIDEO_COIN_RATE_PER_MINUTE',
        (int) env('CALLS_COIN_RATE_PER_MINUTE', 20)
    ),
    'minimum_billable_minutes' => (int) env('CALLS_MINIMUM_BILLABLE_MINUTES', 1),
    'ringing_timeout_seconds' => (int) env('CALLS_RINGING_TIMEOUT_SECONDS', 30),
    'host_share_percent' => (float) env('CALLS_HOST_SHARE_PERCENT', 60),
    'agency_share_percent' => (float) env('CALLS_AGENCY_SHARE_PERCENT', 10),
    'platform_share_percent' => (float) env('CALLS_PLATFORM_SHARE_PERCENT', 30),
    'minimum_balance_to_start_call' => (int) env('CALLS_MINIMUM_BALANCE_TO_START_CALL', 20),
];
