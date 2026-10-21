<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationCategory;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\RecurringDonationSchedule;
use Modules\Donations\Services\RecurringDonationScheduleService;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use PHPUnit\Framework\Attributes\Test;

class DonationsPhaseTwoWorkflowTest extends DonationsCertificationTestCase
{
    use InteractsWithSubscriptions;

    private function duesByPlanPreviewQuery(): array
    {
        return [
            'report_type' => 'dues_by_plan',
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ];
    }
    #[Test]
    public function a_future_due_cannot_be_reminded(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.notifications']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'General',
            'code' => 'GEN-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $ctx['tenant']->id,
            'fund_id' => $fund->id,
            'name' => 'Future festival',
            'code' => 'FUT-'.substr(uniqid(), -4),
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 100,
            'start_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $due = ContributionDue::create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'ONCE-FUTURE',
            'period_start' => now()->addMonth()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson('/api/tenant/donations/dues/'.$due->id.'/remind')
            ->assertStatus(422);
    }

    #[Test]
    public function a_collector_cannot_record_an_expense(): void
    {
        $this->actingAsTenantWith(['donations.view', 'donations.collect']);

        $this->postJson('/api/tenant/donations/expenses', [
            'category' => 'Candles',
            'amount' => 25,
            'currency' => 'USD',
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    #[Test]
    public function an_unallocated_payment_receipt_names_family_credit(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $payment = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '30.00'))
            ->assertCreated()
            ->json('data');

        $receipt = $this->getJson('/api/tenant/donations/payments/'.$payment['id'].'/receipt')->assertOk();
        $descriptions = collect($receipt->json('data.line_items'))->pluck('description');
        $this->assertTrue($descriptions->contains('Family credit'));
    }

    #[Test]
    public function a_collection_purpose_is_filed_on_the_payment_and_named_on_the_receipt(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Festival 2026',
            'code' => 'FEST-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);

        $payment = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '75.00', [
            'allocations' => [[
                'allocatable_type' => 'fund',
                'allocatable_id' => $fund->id,
                'amount' => '75.00',
            ]],
        ]))->assertCreated()->json('data');

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment['id'],
            'allocatable_type' => 'fund',
            'allocatable_id' => $fund->id,
        ]);
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $payment['id'],
            'allocatable_type' => 'advance',
        ]);

        $receipt = $this->getJson('/api/tenant/donations/payments/'.$payment['id'].'/receipt')->assertOk();
        $descriptions = collect($receipt->json('data.line_items'))->pluck('description');
        $this->assertTrue($descriptions->contains('Festival 2026'));
        $this->assertFalse($descriptions->contains('Family credit'));
    }

    #[Test]
    public function a_second_treasurer_must_approve_another_persons_refund(): void
    {
        $ctx = $this->actingAsTenantWith([
            'donations.view',
            'donations.collect',
            'donations.refund',
            'donations.approvals',
        ]);
        $second = User::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'role_id' => $ctx['role']->id,
            'active' => 1,
        ]);
        $second->syncRoles([$ctx['role']->id]);

        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '50.00'))
            ->assertCreated()
            ->json('data.id');
        $refund = $this->postJson('/api/tenant/donations/payments/'.$paymentId.'/refunds', [
            'amount' => '10.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'Overpaid',
        ])->assertCreated()->json('data');

        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'approved',
            'note' => 'Own request',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Another treasurer must approve this refund.');

        Passport::actingAs($second->fresh());
        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'approved',
            'note' => 'Second treasurer',
        ])->assertOk();
    }

    #[Test]
    public function starter_plan_cannot_preview_dues_by_plan_in_enforce_mode(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.reports']);
        $this->assignPlan($ctx['tenant'], 'STARTER');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query($this->duesByPlanPreviewQuery()))
            ->assertForbidden();
    }

    #[Test]
    public function standard_plan_can_preview_dues_by_plan_in_enforce_mode(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.reports']);
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query($this->duesByPlanPreviewQuery()))
            ->assertOk();
    }

    #[Test]
    public function dues_by_plan_preview_requires_donations_reports_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $this->useEntitlementEngine('enforce');

        $this->getJson('/api/tenant/donations/reports/preview?'.http_build_query($this->duesByPlanPreviewQuery()))
            ->assertForbidden();
    }

    #[Test]
    public function same_idempotency_key_on_fund_payment_creates_one_allocation(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Building',
            'code' => 'BLD-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);
        $payload = $this->paymentPayload($family, '44.00', [
            'allocations' => [[
                'allocatable_type' => 'fund',
                'allocatable_id' => $fund->id,
                'amount' => '44.00',
            ]],
        ]);
        $headers = ['Idempotency-Key' => 'collection-day-fund-'.uniqid()];

        $first = $this->postJson('/api/tenant/donations/payments', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/tenant/donations/payments', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DonationPayment::query()->count());
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $first->json('data.id'),
            'allocatable_type' => 'fund',
            'allocatable_id' => $fund->id,
        ]);
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $first->json('data.id'),
            'allocatable_type' => 'advance',
        ]);
    }

    #[Test]
    public function inactive_fund_allocation_is_rejected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Closed purpose',
            'code' => 'CLS-'.substr(uniqid(), -6),
            'status' => 'inactive',
        ]);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00', [
            'allocations' => [[
                'allocatable_type' => 'fund',
                'allocatable_id' => $fund->id,
                'amount' => '20.00',
            ]],
        ]))->assertStatus(422);

        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function cross_tenant_fund_allocation_is_rejected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $otherTenant = Tenant::factory()->active()->create();
        $foreignFund = Fund::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other parish',
            'code' => 'OTH-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '15.00', [
            'allocations' => [[
                'allocatable_type' => 'fund',
                'allocatable_id' => $foreignFund->id,
                'amount' => '15.00',
            ]],
        ]))->assertStatus(422);

        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function cross_tenant_gift_category_is_rejected_on_collect(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $otherTenant = Tenant::factory()->active()->create();
        $foreignCategory = DonationCategory::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Foreign gift',
            'code' => 'FG-'.substr(uniqid(), -6),
            'active' => true,
            'created_by' => $ctx['user']->id,
            'updated_by' => $ctx['user']->id,
        ]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/entries/collect', [
            'family_id' => $family->id,
            'donation_category_id' => $foreignCategory->id,
            'title' => 'Offering',
            'amount' => 25,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payer_name' => 'Test Payer',
        ])->assertStatus(422);
    }

    #[Test]
    public function voluntary_collect_uses_parish_currency_from_resolver(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $this->mock(ChurchCurrencyResolver::class, function ($mock) use ($ctx): void {
            $mock->shouldReceive('currencyCodeForTenantId')
                ->with($ctx['tenant']->id)
                ->andReturn('EUR');
            $mock->shouldReceive('forTenantId')->andReturn(null);
        });

        $response = $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Sunday gift',
            'is_anonymous' => true,
            'amount' => 20,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('EUR', $response->json('data.payment.currency'));
    }

    #[Test]
    public function voluntary_collect_without_parish_currency_is_rejected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $this->mock(ChurchCurrencyResolver::class, function ($mock) use ($ctx): void {
            $mock->shouldReceive('currencyCodeForTenantId')
                ->with($ctx['tenant']->id)
                ->andReturn(null);
            $mock->shouldReceive('forTenantId')->andReturn(null);
        });

        $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Sunday gift',
            'is_anonymous' => true,
            'amount' => 20,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Parish currency is not configured.');
    }

    #[Test]
    public function same_idempotency_key_on_voluntary_collect_does_not_duplicate(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $payload = [
            'title' => 'Repeat gift',
            'is_anonymous' => true,
            'amount' => 18,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ];
        $headers = ['Idempotency-Key' => 'collection-day-offering-'.uniqid()];

        $first = $this->postJson('/api/tenant/donations/entries/collect', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/tenant/donations/entries/collect', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertSame(1, DonationPayment::query()->count());
    }

    #[Test]
    public function voluntary_collect_without_category_still_succeeds(): void
    {
        $this->actingAsTenantWith(['donations.view', 'donations.collect']);

        $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Anonymous gift',
            'is_anonymous' => true,
            'amount' => 12,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();
    }

    #[Test]
    public function parish_gift_category_is_stored_and_named_on_receipt(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $category = DonationCategory::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'Thanksgiving',
            'code' => 'THX-'.substr(uniqid(), -6),
            'active' => true,
            'created_by' => $ctx['user']->id,
            'updated_by' => $ctx['user']->id,
        ]);

        $response = $this->postJson('/api/tenant/donations/entries/collect', [
            'family_id' => $family->id,
            'donation_category_id' => $category->id,
            'title' => 'Thanksgiving',
            'amount' => 80,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payer_name' => $family->family_name,
        ])->assertCreated();

        $this->assertSame($category->id, $response->json('data.donation.donation_category_id'));
        $paymentId = $response->json('data.payment.id');
        $receipt = $this->getJson('/api/tenant/donations/payments/'.$paymentId.'/receipt')->assertOk();
        $descriptions = collect($receipt->json('data.line_items'))->pluck('description');
        $this->assertTrue($descriptions->contains('Thanksgiving'));
    }

    #[Test]
    public function a_held_recurring_lock_does_not_post_the_schedule_twice(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $schedule = RecurringDonationSchedule::create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $family->id,
            'amount' => 40,
            'currency' => 'USD',
            'frequency' => 'monthly',
            'next_run_on' => now()->subDay()->toDateString(),
            'status' => 'active',
            'created_by' => $ctx['user']->id,
            'updated_by' => $ctx['user']->id,
        ]);

        $lock = Cache::lock('donations:recurring-schedule:'.$schedule->id, 120);
        $this->assertTrue($lock->get());

        try {
            $result = app(RecurringDonationScheduleService::class)->runDueSchedules((int) $ctx['tenant']->id);
            $this->assertSame(1, $result['failed']);
            $this->assertSame(0, DonationPayment::query()->where('source_type', 'recurring_schedule')->count());
        } finally {
            $lock->release();
        }
    }
}
