<?php

return [
    'week_starts_at' => env('AGENCY_PAYOUT_WEEK_STARTS_AT', 'monday'),
    'week_ends_at' => env('AGENCY_PAYOUT_WEEK_ENDS_AT', 'sunday'),
    'schedule_day' => (int) env('AGENCY_PAYOUT_SCHEDULE_DAY', 1), // Monday
    'schedule_time' => env('AGENCY_PAYOUT_SCHEDULE_TIME', '00:10'),
    'generate_zero_reports' => (bool) env('AGENCY_PAYOUT_GENERATE_ZERO_REPORTS', true),
];
