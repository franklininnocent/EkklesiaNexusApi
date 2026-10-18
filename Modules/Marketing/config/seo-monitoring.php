<?php

/**
 * SEO monitoring — must stay aligned with EkklesiaNexusWeb/src/lib/seo-monitoring/config.ts
 * when hosts, paths, leak URLs, or sitemap expectations change.
 */
return [
    'schema_version' => 1,

    'apex_host' => 'ekklesianexus.com',
    'base_url' => 'https://ekklesianexus.com',

    'primary_paths' => [
        '/',
        '/pt-br',
        '/features',
        '/solutions/catholic-parishes',
        '/contact',
    ],

    'leak_urls' => [
        'http://dwm.matchpointgps.in/',
        'http://139.59.18.151/',
    ],

    'gsc_verification_path' => '/google161c1efd34d8a358.html',

    'expected_sitemap_loc_count' => 13,

    'user_agent' => 'EkklesiaNexus-SEO-Weekly-Monitor/1.0',

    'request_timeout_seconds' => 20,
    'connect_timeout_seconds' => 10,
    'max_retries' => 2,
    'retry_delay_ms' => 750,
    'max_redirects' => 5,
    'max_body_bytes_html' => 524_288,
    'max_body_bytes_sitemap' => 2_097_152,

    'scan_max_wall_seconds' => 90,
    'lock_lease_seconds' => 120,

    'snapshot_ttl_seconds' => 2_592_000, // 30 days
    'stale_after_seconds' => 604_800, // 7 days

    'cache_keys' => [
        'snapshot_cms' => 'marketing:seo-monitoring:snapshot:cms',
        'snapshot_production' => 'marketing:seo-monitoring:snapshot:production',
        'last_failed' => 'marketing:seo-monitoring:last_failed',
        'active_scan' => 'marketing:seo-monitoring:active_scan',
        'lock' => 'marketing:seo-monitoring:scan',
    ],

    /** Production HTTP checks shown as not_run when production scan skipped. */
    'production_check_stubs' => [
        ['check_id' => 'robots-txt', 'message' => 'robots.txt (live)'],
        ['check_id' => 'sitemap-http', 'message' => 'Sitemap HTTP (live)'],
        ['check_id' => 'gsc-file-live', 'message' => 'GSC verification file (production)'],
    ],
];
