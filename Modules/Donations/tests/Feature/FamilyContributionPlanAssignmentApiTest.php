<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\Fund;
use Modules\Family\Models\Family;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class FamilyContributionPlanAssignmentApiTest extends DonationsCertificationTestCase
{
    use RefreshDatabase;

    private function individualPlan(int $tenantId, string $code = 'IND'): ContributionPlan
    {
        $fund = Fund::create([
            'tenant_id' => $tenantId,
            'name' => 'Fund',
            'code' => 'F-'.$code,
            'status' => 'active',
        ]);

        return ContributionPlan::create([
            'tenant_id' => $tenantId,
            'fund_id' => $fund->id,
            'name' => 'Individual Plan '.$code,
            'code' => $code,
            'plan_type' => 'individual',
            'frequency' => 'yearly',
            'default_amount' => 1,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function assignmentUrl(string $familyId): string
    {
        return "/api/tenant/donations/families/{$familyId}/contribution-plan-assignments";
    }

    #[Test]
    public function it_creates_family_specific_amount_for_individual_plan(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $familyA = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $familyB = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $plan = $this->individualPlan($ctx['tenant']->id, 'PLAN_A');

        $response = $this->postJson($this->assignmentUrl($familyA->id), [
            'plan_id' => $plan->id,
            'amount' => 2000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '2000.00')
            ->assertJsonPath('data.family_id', $familyA->id);

        $this->assertDatabaseHas('contribution_plan_assignments', [
            'tenant_id' => $ctx['tenant']->id,
            'plan_id' => $plan->id,
            'family_id' => $familyA->id,
            'amount' => 2000,
            'is_exempt' => false,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('contribution_plan_assignments', [
            'plan_id' => $plan->id,
            'family_id' => $familyB->id,
        ]);
    }

    #[Test]
    public function it_updates_amount_in_place_without_duplicate_rows(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $plan = $this->individualPlan($ctx['tenant']->id, 'PLAN_B');
        $effectiveFrom = now()->toDateString();

        ContributionPlanAssignment::create([
            'tenant_id' => $ctx['tenant']->id,
            'plan_id' => $plan->id,
            'family_id' => $family->id,
            'amount' => 1000,
            'effective_from' => $effectiveFrom,
            'is_exempt' => false,
            'status' => 'active',
        ]);

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 2500,
        ])->assertCreated();

        $this->assertSame(
            1,
            ContributionPlanAssignment::query()
                ->where('plan_id', $plan->id)
                ->where('family_id', $family->id)
                ->count()
        );

        $this->assertDatabaseHas('contribution_plan_assignments', [
            'plan_id' => $plan->id,
            'family_id' => $family->id,
            'effective_from' => $effectiveFrom,
            'amount' => 2500,
        ]);
    }

    #[Test]
    public function it_creates_new_row_when_only_expired_assignment_exists(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $plan = $this->individualPlan($ctx['tenant']->id, 'PLAN_C');

        ContributionPlanAssignment::create([
            'tenant_id' => $ctx['tenant']->id,
            'plan_id' => $plan->id,
            'family_id' => $family->id,
            'amount' => 500,
            'effective_from' => now()->subMonths(6)->toDateString(),
            'effective_to' => now()->subMonth()->toDateString(),
            'is_exempt' => false,
            'status' => 'active',
        ]);

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 1800,
        ])->assertCreated();

        $this->assertSame(2, ContributionPlanAssignment::query()
            ->where('plan_id', $plan->id)
            ->where('family_id', $family->id)
            ->count());

        $this->assertDatabaseHas('contribution_plan_assignments', [
            'plan_id' => $plan->id,
            'family_id' => $family->id,
            'amount' => 500,
        ]);

        $this->assertDatabaseHas('contribution_plan_assignments', [
            'plan_id' => $plan->id,
            'family_id' => $family->id,
            'amount' => 1800,
        ]);
    }

    #[Test]
    public function it_rejects_uniform_plan(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $fund = Fund::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Uniform Fund',
            'code' => 'UNI',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $ctx['tenant']->id,
            'fund_id' => $fund->id,
            'name' => 'Uniform',
            'code' => 'UNIFORM',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 1500,
            'status' => 'active',
        ]);

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 2000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    }

    #[Test]
    public function it_rejects_inactive_plan(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $plan = $this->individualPlan($ctx['tenant']->id, 'INACTIVE');
        $plan->update(['status' => 'inactive']);

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    }

    #[Test]
    public function it_returns_404_for_family_from_another_tenant(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $other = $this->makeTenantUser(['donations.view', 'donations.manage']);
        $foreignFamily = Family::factory()->create(['tenant_id' => $other['tenant']->id]);
        $plan = $this->individualPlan($ctx['tenant']->id, 'OWN');

        $this->postJson($this->assignmentUrl($foreignFamily->id), [
            'plan_id' => $plan->id,
            'amount' => 100,
        ])->assertNotFound();
    }

    #[Test]
    public function it_rejects_plan_from_another_tenant(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $other = $this->makeTenantUser(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $foreignPlan = $this->individualPlan($other['tenant']->id, 'FOREIGN');

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $foreignPlan->id,
            'amount' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    }

    #[Test]
    public function it_requires_donations_manage_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $plan = $this->individualPlan($ctx['tenant']->id, 'AUTH');

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 100,
        ])->assertForbidden();
    }

    #[Test]
    public function it_validates_amount(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $plan = $this->individualPlan($ctx['tenant']->id, 'VAL');

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => -5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function it_does_not_create_dues_or_payments(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $plan = $this->individualPlan($ctx['tenant']->id, 'LEDGER');

        $duesBefore = \Modules\Donations\Models\ContributionDue::query()->count();
        $paymentsBefore = DonationPayment::query()->count();

        $this->postJson($this->assignmentUrl($family->id), [
            'plan_id' => $plan->id,
            'amount' => 999,
        ])->assertCreated();

        $this->assertSame($duesBefore, \Modules\Donations\Models\ContributionDue::query()->count());
        $this->assertSame($paymentsBefore, DonationPayment::query()->count());
    }
}
