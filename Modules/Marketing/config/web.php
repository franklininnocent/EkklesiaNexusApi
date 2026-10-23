<?php

/**
 * Website-wide (platform) contact / CAPTCHA defaults.
 * Secrets stay in environment / Laravel mail config — never here.
 */
return [
    'default_contact_recipient' => 'contact@ekklesianexus.com',

    'captcha' => [
        'length' => 5,
        'ttl_seconds' => (int) env('WEB_CONTACT_CAPTCHA_TTL', 300),
        'max_attempts' => (int) env('WEB_CONTACT_CAPTCHA_MAX_ATTEMPTS', 5),
        'charset' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
    ],

    'enquiry' => [
        'message_max' => 5000,
        'name_max' => 120,
        'organisation_max' => 200,
        'phone_max' => 40,
        'retention_days' => (int) env('WEB_CONTACT_ENQUIRY_RETENTION_DAYS', 730),
    ],

    'mail' => [
        'max_tries' => 5,
        'backoff_seconds' => [60, 120, 300, 600, 900],
        /** Enquiries left at delivery_status=received longer than this are retried by the scheduler. */
        'stuck_grace_seconds' => (int) env('WEB_CONTACT_DELIVERY_STUCK_GRACE_SECONDS', 120),
    ],
];
