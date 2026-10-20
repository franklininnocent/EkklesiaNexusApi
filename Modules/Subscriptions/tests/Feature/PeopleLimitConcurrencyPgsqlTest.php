<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\Usage\PeopleUsageProvider;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

/**
 * Requires PostgreSQL (not SQLite). Validates last-slot races for PEOPLE_LIMIT.
 */
#[Group('pgsql')]
class PeopleLimitConcurrencyPgsqlTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for concurrency coverage.');
        }
    }

    #[Test]
    public function two_concurrent_single_member_creates_cannot_exceed_limit(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->limitOverride($ctx['tenant'], 'PEOPLE_LIMIT', 2);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        FamilyMember::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $family->id,
            'status' => 'active',
        ]);
        $this->useEntitlementEngine('enforce');

        $responses = [];
        $pid = getmypid();
        for ($i = 0; $i < 2; $i++) {
            $responses[] = $this->postJson("/api/families/{$family->id}/members", [
                'first_name' => "Race{$i}",
                'last_name' => "P{$pid}",
                'relationship_to_head' => 'child',
                'status' => 'active',
            ]);
        }

        $success = collect($responses)->filter(fn ($r) => $r->status() === 201)->count();
        $this->assertSame(1, $success);
        $this->assertSame(2, app(PeopleUsageProvider::class)->currentUsage((int) $ctx['tenant']->id));
    }

    private function limitOverride(Tenant $tenant, string $code, int $value): void
    {
        TenantEntitlementOverride::query()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::query()->where('code', $code)->value('id'),
            'mode' => TenantEntitlementOverride::MODE_SET_LIMIT,
            'numeric_value' => $value,
            'reason' => 'Test limit',
        ]);
        app(EntitlementResolver::class)->forget((int) $tenant->id);
    }
}
