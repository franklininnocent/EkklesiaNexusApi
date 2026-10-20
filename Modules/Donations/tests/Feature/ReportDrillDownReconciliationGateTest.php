<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\Fund;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownReconciliationGateTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function it_paginates_all_payment_rows_matching_chart_total(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $date = now()->startOfMonth()->toDateString();
        foreach ([10, 20, 30] as $i => $amount) {
            DonationPayment::create([
                'tenant_id' => $this->tenant->id,
                'family_id' => $family->id,
                'payment_number' => 'PAY-PAGE-'.$i,
                'payer_name' => 'Pager '.$i,
                'payment_date' => $date,
                'amount' => $amount,
                'currency' => 'INR',
                'method' => 'cash',
                'status' => 'succeeded',
                'source_type' => 'general',
            ]);
        }

        $expected = 0.0;
        $seen = 0;
        $lastPage = 1;
        for ($page = 1; $page <= 10; $page++) {
            $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
                'graph_id' => 'collections',
                'data_element_id' => 'current_month_collected',
                'slice_id' => 'current_month',
                'per_page' => 1,
                'page' => $page,
            ]));
            $drill->assertOk();
            if ($page === 1) {
                $expected = (float) $drill->json('data.context.expected_amount');
                $lastPage = (int) $drill->json('data.data.last_page');
            }
            $rows = $drill->json('data.data.data') ?? [];
            foreach ($rows as $row) {
                $expected -= (float) $row['amount'];
                $seen++;
            }
            if ($page >= $lastPage) {
                break;
            }
        }

        $this->assertSame(3, $seen);
        $this->assertTrue(MoneyMath::equals(0, $expected));
    }

    #[Test]
    public function search_filter_changes_summary_but_not_chart_expected_amount(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Smith Household',
        ]);
        $date = now()->startOfMonth()->toDateString();
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-A',
            'payer_name' => 'Alpha Payer',
            'payment_date' => $date,
            'amount' => 40,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-B',
            'payer_name' => 'Beta Payer',
            'payment_date' => $date,
            'amount' => 60,
            'currency' => 'INR',
            'method' => 'bank_transfer',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $base = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));
        $base->assertOk();
        $chartAmount = (float) $base->json('data.context.expected_amount');

        $filtered = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'search' => 'Alpha',
        ]));
        $filtered->assertOk();
        $this->assertSame($chartAmount, (float) $filtered->json('data.context.expected_amount'));
        $this->assertSame(40.0, (float) $filtered->json('data.summary.amount_total'));
    }

    #[Test]
    public function it_counts_two_payments_as_two_rows_and_one_participant(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $today = now()->startOfMonth()->toDateString();
        foreach (['cash', 'cheque'] as $i => $method) {
            DonationPayment::create([
                'tenant_id' => $this->tenant->id,
                'family_id' => $family->id,
                'payment_number' => 'PAY-DUP-'.$i,
                'payer_name' => 'Dup '.$i,
                'payment_date' => $today,
                'amount' => 25,
                'currency' => 'INR',
                'method' => $method,
                'status' => 'succeeded',
                'source_type' => 'general',
            ]);
        }

        $payments = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'per_page' => 50,
        ]));
        $payments->assertOk();
        $this->assertSame(2, (int) $payments->json('data.data.total'));

        $part = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'family_participation',
            'data_element_id' => 'participating_families',
            'slice_id' => 'participating',
        ]));
        $part->assertOk();
        $this->assertSame(1, (int) $part->json('data.context.expected_count'));
    }

    #[Test]
    public function it_excludes_refunded_and_reversed_payments_from_collections_drill_down(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $date = now()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-GOOD',
            'payer_name' => 'Good',
            'payment_date' => $date,
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARTIAL',
            'payer_name' => 'Partial',
            'payment_date' => $date,
            'amount' => 100,
            'refunded_amount' => 25,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-REFUNDED',
            'payer_name' => 'Refunded',
            'payment_date' => $date,
            'amount' => 500,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'refunded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-REVERSED',
            'payer_name' => 'Reversed',
            'payment_date' => $date,
            'amount' => 500,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'reversed',
            'source_type' => 'general',
        ]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));
        $drill->assertOk();
        $this->assertSame(200.0, (float) $drill->json('data.context.expected_amount'));
        $this->assertSame(2, (int) $drill->json('data.data.total'));
    }

    #[Test]
    public function participation_slices_sum_to_active_families(): void
    {
        $paying = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $paying->id,
            'payment_number' => 'PAY-SUM',
            'payer_name' => 'One',
            'payment_date' => DonationBusinessDate::today($this->tenant->id),
            'amount' => 50,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $active = (int) $executive->json('data.visuals.participation.active_families');
        $participating = (int) $executive->json('data.visuals.participation.participating_families');

        $notDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'family_participation',
            'data_element_id' => 'not_participating',
            'slice_id' => 'not_participating',
        ]))->assertOk();

        $this->assertSame($active - $participating, (int) $notDrill->json('data.context.expected_count'));
    }

    #[Test]
    public function empty_tenant_returns_200_with_empty_drill_down(): void
    {
        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));
        $drill->assertOk()
            ->assertJsonPath('data.data.total', 0)
            ->assertJsonPath('data.context.expected_amount', 0);
    }

    #[Test]
    public function project_funding_drill_down_caps_at_five_active_projects(): void
    {
        for ($i = 0; $i < 6; $i++) {
            DonationProject::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Project '.$i,
                'code' => 'PRJ-'.$i,
                'status' => 'active',
                'target_amount' => 1000,
                'raised_amount' => 100 * ($i + 1),
            ]);
        }

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'project_funding',
            'slice_id' => 'project_funding',
        ]));
        $drill->assertOk();
        $this->assertSame(5, (int) $drill->json('data.data.total'));
        $this->assertSame(5, (int) $drill->json('data.context.expected_count'));
    }

    #[Test]
    public function overdue_drill_family_amount_excludes_non_overdue_dues(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F1',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Plan',
            'code' => 'P1',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'overdue',
            'due_date' => now()->subDays(5)->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'future',
            'due_date' => now()->addDays(10)->toDateString(),
            'amount_due' => 400,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'outstanding_overdue',
            'data_element_id' => 'overdue_amount',
            'slice_id' => 'overdue',
        ]));
        $drill->assertOk();
        $row = collect($drill->json('data.data.data'))->firstWhere('family_id', $family->id);
        $this->assertNotNull($row);
        $this->assertSame(100.0, (float) $row['outstanding_amount']);
    }

    #[Test]
    public function collections_drill_down_query_uses_index_friendly_plan_on_sqlite(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PLAN',
            'payer_name' => 'Plan',
            'payment_date' => now()->startOfMonth()->toDateString(),
            'amount' => 10,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        if (DB::getDriverName() === 'sqlite') {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT id FROM donation_payments WHERE tenant_id = ? AND status = ? AND payment_date BETWEEN ? AND ?', [
                $this->tenant->id,
                'succeeded',
                now()->startOfMonth()->toDateString(),
                now()->toDateString(),
            ]);
            $this->assertNotEmpty($plan);
        }

        $started = microtime(true);
        $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'per_page' => 50,
        ]))->assertOk();
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    #[Test]
    public function project_funding_drill_down_excludes_inactive_projects(): void
    {
        $inactive = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inactive recent',
            'code' => 'PRJ-OFF',
            'status' => 'cancelled',
            'target_amount' => 1000,
            'raised_amount' => 900,
            'updated_at' => now(),
        ]);
        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Active one',
            'code' => 'PRJ-ON',
            'status' => 'active',
            'target_amount' => 1000,
            'raised_amount' => 200,
        ]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'project_funding',
            'slice_id' => 'project_funding',
        ]));
        $drill->assertOk();
        $ids = collect($drill->json('data.data.data'))->pluck('project_id')->all();
        $this->assertNotContains($inactive->id, $ids);
        $this->assertSame(1, (int) $drill->json('data.data.total'));
    }

    #[Test]
    public function remaining_collectable_drill_omits_diagnostics_when_record_scope_matches_residual(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F-REM',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Plan',
            'code' => 'P-REM',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $businessDate = DonationBusinessDate::today($this->tenant->id);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'future',
            'period_start' => Carbon::parse($businessDate)->subDays(10)->toDateString(),
            'due_date' => Carbon::parse($businessDate)->addDays(5)->toDateString(),
            'amount_due' => 80,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'overdue',
            'due_date' => now()->subDays(3)->toDateString(),
            'amount_due' => 20,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'outstanding_overdue',
            'data_element_id' => 'remaining_collectable',
            'slice_id' => 'remaining_collectable',
        ]));
        $drill->assertOk();
        $this->assertNull($drill->json('data.context.diagnostics'));
        $this->assertSame(80.0, (float) $drill->json('data.context.expected_amount'));
    }

    #[Test]
    public function executive_summary_reconciles_stewardship_score_and_due_schedule_partition(): void
    {
        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();

        $factors = $executive->json('data.visuals.health.factors') ?? [];
        $this->assertNotEmpty($factors);

        $weighted = round(collect($factors)->reduce(function (float $carry, array $factor): float {
            if (($factor['not_applicable'] ?? false) === true) {
                return $carry;
            }

            return $carry + ((float) ($factor['score'] ?? 0) * ((float) ($factor['weight_pct'] ?? 0) / 100));
        }, 0.0), 1);

        $this->assertSame(
            (float) $executive->json('data.visuals.health.score'),
            $weighted
        );
        $this->assertSame(
            (float) $executive->json('data.metrics.health_score'),
            (float) $executive->json('data.visuals.health.score')
        );

        $outstanding = $executive->json('data.visuals.outstanding') ?? [];
        $partitionSum = (float) ($outstanding['overdue_amount'] ?? 0)
            + (float) ($outstanding['next_14_days_amount'] ?? 0)
            + (float) ($outstanding['later_remaining_amount'] ?? 0);

        $this->assertEqualsWithDelta(
            (float) ($outstanding['pending_dues'] ?? 0),
            $partitionSum,
            0.02
        );

        $snapshot = $executive->json('data.visuals.collection_snapshot') ?? [];
        $this->assertArrayHasKey('collected_label', $snapshot);
        $this->assertSame('due_allocated', $snapshot['collected_label']);
    }

    #[Test]
    public function forecast_drill_projection_matches_executive_forecast_signal(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $date = now()->startOfMonth()->toDateString();
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FCST',
            'payer_name' => 'Forecast',
            'payment_date' => $date,
            'amount' => 310,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $projection = (float) $executive->json('data.visuals.forecast.current_month_projection');

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'month_end_forecast',
            'data_element_id' => 'current_month_projection',
            'slice_id' => 'current_month_projection',
        ]));
        $drill->assertOk()
            ->assertJsonPath('data.context.point_kind', 'forecast');
        $this->assertSame($projection, (float) $drill->json('data.context.expected_amount'));

        $mtdDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'month_end_forecast',
            'data_element_id' => 'collected',
            'slice_id' => now()->format('Y-m'),
        ]));
        $mtdDrill->assertOk();
        $this->assertSame(
            (float) $drill->json('data.context.methodology.inputs.current_month_collected'),
            (float) $mtdDrill->json('data.context.expected_amount')
        );
    }
}
