<?php

return [
    'video' => [
        'max_participants' => (int) env('LIVE_VIDEO_MAX_PARTICIPANTS', 12),
        'max_speakers' => (int) env('LIVE_VIDEO_MAX_SPEAKERS', 4),
    ],
    'audio' => [
        'max_participants' => (int) env('LIVE_AUDIO_MAX_PARTICIPANTS', 50),
        'max_speakers' => (int) env('LIVE_AUDIO_MAX_SPEAKERS', 8),
    ],
    'speaker_requests' => [
        'video_auto_approve' => (bool) env('LIVE_VIDEO_SPEAKER_REQUESTS_AUTO_APPROVE', false),
        'audio_auto_approve' => (bool) env('LIVE_AUDIO_SPEAKER_REQUESTS_AUTO_APPROVE', false),
    ],
    'pk' => [
        'default_duration_seconds' => (int) env('LIVE_PK_DEFAULT_DURATION_SECONDS', 300),
    ],
];
