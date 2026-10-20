<?php

return [
    'name' => 'ApplicationAccess',

    /*
    |--------------------------------------------------------------------------
    | Telemetry master switch
    |--------------------------------------------------------------------------
    |
    | When false, capture middleware and recorders are no-ops (rollback / safe deploy).
    | Enabled in Phase 6 after sanitizer tests pass.
    |
    */
    'telemetry_enabled' => filter_var(
        env('APPLICATION_ACCESS_TELEMETRY_ENABLED', env('APP_ENV') === 'testing' ? false : true),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    |--------------------------------------------------------------------------
    | Session display
    |--------------------------------------------------------------------------
    */
    'idle_minutes' => (int) env('APPLICATION_ACCESS_IDLE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | SSE stream limits (Phase 9)
    |--------------------------------------------------------------------------
    */
    'sse' => [
        'max_connections' => (int) env('APPLICATION_ACCESS_SSE_MAX_CONNECTIONS', 10),
        'ttl_seconds' => (int) env('APPLICATION_ACCESS_SSE_TTL', 120),
        'heartbeat_seconds' => (int) env('APPLICATION_ACCESS_SSE_HEARTBEAT', 20),
        'replay_max_seconds' => (int) env('APPLICATION_ACCESS_SSE_REPLAY_MAX_SECONDS', 120),
        'replay_max_rows' => (int) env('APPLICATION_ACCESS_SSE_REPLAY_MAX_ROWS', 100),
        'poll_interval_ms' => (int) env('APPLICATION_ACCESS_SSE_POLL_INTERVAL_MS', 1000),
        'poll_batch_size' => (int) env('APPLICATION_ACCESS_SSE_POLL_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture throttles (Phase 6)
    |--------------------------------------------------------------------------
    */
    'view_throttle_seconds' => (int) env('APPLICATION_ACCESS_VIEW_THROTTLE', 60),
    'session_touch_seconds' => (int) env('APPLICATION_ACCESS_SESSION_TOUCH', 60),
    'investigation_audit_ttl_seconds' => (int) env('APPLICATION_ACCESS_INVESTIGATION_AUDIT_TTL', 21600),

    /*
    |--------------------------------------------------------------------------
    | Retention defaults (Phase 10) — days
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'access_events_days' => (int) env('APPLICATION_ACCESS_RETENTION_EVENTS', 90),
        'security_events_days' => (int) env('APPLICATION_ACCESS_RETENTION_SECURITY', 180),
        'signals_days' => (int) env('APPLICATION_ACCESS_RETENTION_SIGNALS', 90),
        'ended_sessions_days' => (int) env('APPLICATION_ACCESS_RETENTION_SESSIONS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler (Phase 10)
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'retention_enabled' => env('APPLICATION_ACCESS_RETENTION_SCHEDULER_ENABLED', true),
        'retention_day' => env('APPLICATION_ACCESS_RETENTION_DAY', 'sunday'),
        'retention_time' => env('APPLICATION_ACCESS_RETENTION_TIME', '03:30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoIP (optional — Phase 3+)
    |--------------------------------------------------------------------------
    */
    'geoip_database' => env('GEOIP_DATABASE'),

    /*
    |--------------------------------------------------------------------------
    | Flood / signal thresholds (Phase 8)
    |--------------------------------------------------------------------------
    */
    'flood' => [
        'anonymous_events_per_minute' => (int) env('APPLICATION_ACCESS_FLOOD_ANON_PER_MIN', 30),
        'invalid_token_per_minute' => (int) env('APPLICATION_ACCESS_FLOOD_INVALID_TOKEN', 20),
    ],
];
