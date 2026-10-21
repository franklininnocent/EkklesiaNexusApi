<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\Fund;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FundCreateAuthorizationTest extends DonationsCertificationTestCase
{
    #[Test]
    public function fund_create_requires_donations_manage_permission(): void
    {
        $this->actingAsTenantWith(['donations.view']);

        $response = $this->postJson('/api/tenant/donations/funds', [
            'name' => 'Restricted Fund',
            'code' => 'RESTRICTED',
            'status' => 'active',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function fund_create_stamps_actor_tenant_and_ignores_client_tenant_id(): void
    {
        $context = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $otherTenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);

        $response = $this->postJson('/api/tenant/donations/funds', [
            'name' => 'Parish Fund',
            'code' => 'PARISH_FUND',
            'status' => 'active',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant_id', $context['tenant']->id);

        $fund = Fund::query()->findOrFail($response->json('data.id'));
        $this->assertSame($context['tenant']->id, $fund->tenant_id);
        $this->assertNotSame($otherTenant->id, $fund->tenant_id);
    }
}
