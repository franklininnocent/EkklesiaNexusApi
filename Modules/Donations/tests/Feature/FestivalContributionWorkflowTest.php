<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\Fund;
use Modules\Donations\Services\Reports\DuesByPlanReportBuilder;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FestivalContributionWorkflowTest extends DonationsCertificationTestCase
{
    #[Test]
    public function one_time_uniform_plan_bills_each_eligible_family_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'Asia/Kolkata'));

        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage', 'donations.collect', 'donations.reports']);
        $tenantId = $ctx['tenant']->id;
        $fund = $this->makeFund($tenantId);
        $standard = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $special = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $exempt = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $planId = $this->postJson('/api/tenant/donations/plans', [
            'fund_id' => $fund->id,
            'name' => 'Festival Contribution 2026',
            'code' => 'FEST-2026',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 500,
            'start_date' => '2026-12-25',
            'auto_generate' => false,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/tenant/donations/plans/{$planId}/assignments", [
            'family_id' => $exempt->id,
            'amount' => 0,
            'effective_from' => '2026-12-25',
            'is_exempt' => true,
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/plans/{$planId}/assignments", [
            'family_id' => $special->id,
            'amount' => 250,
            'effective_from' => '2026-12-25',
            'is_exempt' => false,
        ])->assertCreated();

        $preview = $this->getJson("/api/tenant/donations/plans/{$planId}/generation-preview")->assertOk();
        $preview->assertJsonPath('data.eligible_family_count', 2);
        $preview->assertJsonPath('data.exempt_family_count', 1);
        $preview->assertJsonPath('data.already_billed_count', 0);
        $this->assertEquals(750, $preview->json('data.expected_new_amount'));

        $this->postJson("/api/tenant/donations/plans/{$planId}/generate-dues", [
            'generate_full_schedule' => true,
        ])->assertOk()
            ->assertJsonPath('data.generated_count', 2)
            ->assertJsonPath('data.exempt_skipped_count', 1);

        $this->assertNull(ContributionDue::query()->where('family_id', $exempt->id)->first());
        $this->assertSame('500.00', (string) ContributionDue::query()->where('family_id', $standard->id)->value('amount_due'));
        $this->assertSame('250.00', (string) ContributionDue::query()->where('family_id', $special->id)->value('amount_due'));

        $repeat = $this->postJson("/api/tenant/donations/plans/{$planId}/generate-dues", [
            'generate_full_schedule' => true,
        ])->assertOk();
        $repeat->assertJsonPath('data.generated_count', 0);
        $repeat->assertJsonPath('data.unchanged_count', 2);
        $this->assertSame(2, ContributionDue::query()->where('plan_id', $planId)->count());
        $this->assertSame('500.00', (string) ContributionDue::query()->where('family_id', $standard->id)->value('amount_due'));

        $dueId = ContributionDue::query()->where('family_id', $standard->id)->value('id');
        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $standard->id,
            'payer_name' => 'Festival payer',
            'payment_date' => '2026-10-21',
            'amount' => 40,
            'method' => 'cash',
            'allocations' => [
                ['allocatable_type' => 'due', 'allocatable_id' => $dueId, 'amount' => 40],
            ],
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/plans/{$planId}/assignments", [
            'family_id' => $standard->id,
            'amount' => 100,
            'effective_from' => '2026-12-25',
            'is_exempt' => false,
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/plans/{$planId}/generate-dues", [
            'generate_full_schedule' => true,
        ])->assertOk();

        $settled = ContributionDue::query()->find($dueId);
        $this->assertSame('500.00', (string) $settled->amount_due);
        $this->assertSame('40.00', (string) $settled->amount_paid);

        $this->getJson('/api/tenant/donations/dues?plan_id='.$planId.'&actionable=true')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $roster = $this->getJson('/api/tenant/donations/dues?plan_id='.$planId)->assertOk();
        $this->assertSame(2, $roster->json('data.total'));

        $this->putJson("/api/tenant/donations/plans/{$planId}", ['status' => 'inactive'])->assertOk();
        $this->postJson("/api/tenant/donations/plans/{$planId}/generate-dues", [
            'generate_full_schedule' => true,
        ])->assertStatus(422);

        Carbon::setTestNow();
    }

    #[Test]
    public function assignment_rejects_a_family_from_another_parish(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $other = $this->makeTenantUser(['donations.view']);
        $fund = $this->makeFund($ctx['tenant']->id);
        $foreign = Family::factory()->create(['tenant_id' => $other['tenant']->id, 'status' => 'active']);
        $plan = ContributionPlan::create([
            'tenant_id' => $ctx['tenant']->id,
            'fund_id' => $fund->id,
            'name' => 'Festival',
            'code' => 'FEST-TENANT',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 500,
            'start_date' => '2026-12-25',
            'status' => 'active',
        ]);

        $this->postJson("/api/tenant/donations/plans/{$plan->id}/assignments", [
            'family_id' => $foreign->id,
            'amount' => 500,
            'effective_from' => '2026-12-25',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_held_plan_lock_rejects_a_second_billing_request(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $fund = $this->makeFund($ctx['tenant']->id);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $plan = ContributionPlan::create([
            'tenant_id' => $ctx['tenant']->id,
            'fund_id' => $fund->id,
            'name' => 'Locked festival',
            'code' => 'FEST-LOCK',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 500,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $lock = Cache::lock("donations:generate-schedule:{$ctx['tenant']->id}:{$plan->id}", 60);
        $this->assertTrue($lock->get());

        try {
            $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
                'generate_full_schedule' => true,
            ])->assertStatus(409);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function a_category_does_not_create_family_dues(): void
    {
        $this->actingAsTenantWith(['donations.view', 'donations.manage']);

        $this->postJson('/api/tenant/donations/categories', [
            'name' => 'Festival gift',
            'code' => 'FEST-GIFT',
            'amount' => 500,
        ])->assertCreated();

        $this->assertSame(0, ContributionDue::query()->count());
    }

    #[Test]
    public function a_reversed_payment_cannot_receive_a_replacement_receipt(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage', 'donations.reverse']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $payment = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '25.00'))
            ->assertCreated()
            ->json('data');
        $receipt = DonationReceipt::query()->where('payment_id', $payment['id'])->firstOrFail();

        $this->postJson('/api/tenant/donations/payments/'.$payment['id'].'/reverse', [
            'reason' => 'Recorded on the wrong family',
        ])->assertOk();

        $reissue = $this->postJson('/api/tenant/donations/receipts/'.$receipt->id.'/reissue', [
            'reason' => 'Print again',
        ]);

        $this->assertGreaterThanOrEqual(400, $reissue->status());
        $this->assertSame(0, DonationReceipt::query()->where('payment_id', $payment['id'])->where('is_void', false)->count());
    }

    #[Test]
    public function a_campaign_cannot_generate_family_instalments(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $fund = $this->makeFund($ctx['tenant']->id);
        $campaign = $this->postJson('/api/tenant/donations/campaigns', [
            'fund_id' => $fund->id,
            'name' => 'Feast appeal',
            'code' => 'FEAST-APPEAL',
            'campaign_type' => 'event',
            'target_amount' => 10000,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/tenant/donations/projects/{$campaign}/generate-installments")->assertNotFound();
        $this->getJson("/api/tenant/donations/projects/{$campaign}")->assertNotFound();
    }

    #[Test]
    public function waived_dues_do_not_increase_dues_by_plan_outstanding(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.reports']);
        $fund = $this->makeFund($ctx['tenant']->id);
        $plan = ContributionPlan::create([
            'tenant_id' => $ctx['tenant']->id,
            'fund_id' => $fund->id,
            'name' => 'Festival report',
            'code' => 'FEST-RPT',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 100,
            'start_date' => '2026-06-01',
            'status' => 'active',
        ]);
        $open = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $waived = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $this->makeDue($ctx['tenant'], $plan, $open, '100.00', 'pending');
        $this->makeDue($ctx['tenant'], $plan, $waived, '80.00', 'waived');

        $report = app(DuesByPlanReportBuilder::class)->preview(
            (int) $ctx['tenant']->id,
            ReportFilter::fromValidated(DonationReportCatalog::TYPE_DUES_BY_PLAN, [
                'plan_id' => $plan->id,
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ]),
        );
        $row = collect($report['rows'])->firstWhere('plan_name', 'Festival report');
        $this->assertNotNull($row);
        $this->assertEquals(100, $row['outstanding']);
        $this->assertSame(1, $row['waived_count']);
    }

    private function makeFund(int $tenantId): Fund
    {
        return Fund::create([
            'tenant_id' => $tenantId,
            'name' => 'Festival fund',
            'code' => 'FUND-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);
    }

    private function makeDue(Tenant $tenant, ContributionPlan $plan, Family $family, string $amount, string $status): void
    {
        ContributionDue::create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'ONCE-20260601',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-01',
            'due_date' => '2026-06-01',
            'amount_due' => $amount,
            'amount_paid' => 0,
            'status' => $status,
        ]);
    }
}
