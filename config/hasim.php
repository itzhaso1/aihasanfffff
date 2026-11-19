<?php

return [
    'stories' => [
        'default_expires_hours' => (int) env('HASIM_STORY_EXPIRES_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idle Handoff Auto-Resume
    |--------------------------------------------------------------------------
    | When a conversation has an active human handoff and the assigned employee
    | hasn't replied for this many minutes (and has no live presence), AI is
    | automatically resumed. Set to 0 to disable.
    */
    'idle_handoff_resume_minutes' => (int) env('IDLE_HANDOFF_RESUME_MINUTES', 5),
];
