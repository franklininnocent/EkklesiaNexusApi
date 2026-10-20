<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    private const APP_ORIGIN = 'https://app.ekklesianexus.com';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cors.allowed_origins' => [self::APP_ORIGIN],
            'cors.allowed_headers' => array_values(array_unique(array_merge(
                config('cors.allowed_headers', []),
                ['Idempotency-Key']
            ))),
        ]);
    }

    #[Test]
    public function donations_payment_preflight_allows_idempotency_key_for_app_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => self::APP_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,authorization,idempotency-key',
        ])->options('/api/tenant/donations/payments');

        $response->assertSuccessful();
        $response->assertHeader('Access-Control-Allow-Origin', self::APP_ORIGIN);

        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('idempotency-key', $allowedHeaders);
        $this->assertStringContainsString('authorization', $allowedHeaders);
        $this->assertStringContainsString('content-type', $allowedHeaders);
    }

    #[Test]
    public function cors_configuration_allows_idempotency_key_header(): void
    {
        $this->assertContains('Idempotency-Key', config('cors.allowed_headers', []));
    }

    #[Test]
    public function unauthenticated_payment_post_is_still_rejected(): void
    {
        $this->postJson('/api/tenant/donations/payments', [
            'amount' => 10,
            'method' => 'cash',
        ])->assertUnauthorized();
    }
}
