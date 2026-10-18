<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => \App\Support\CorsAllowedOrigins::resolve(
        env('APP_ENV', 'production'),
        env('FRONTEND_URL'),
        env('MARKETING_URL'),
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
        'X-Tenant-Country',
        'X-Support-Session-Id',
        'X-Correlation-Id',
        'Idempotency-Key',
        'X-Marketing-Proxy-Secret',
        'X-Marketing-Client-Ip',
    ],

    'exposed_headers' => [
        'Content-Disposition',
        'Content-Length',
        'X-Content-Type-Options',
    ],

    'max_age' => 3600, // Cache preflight requests for 1 hour

    'supports_credentials' => true, // Required for auth tokens

];

