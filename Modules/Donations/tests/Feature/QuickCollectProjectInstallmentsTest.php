<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class QuickCollectProjectInstallmentsTest extends DonationsCertificationTestCase
{
    /**
     * @return array{project_id: string, family: Family, installments: \Illuminate\Support\Collection<int, ProjectInstallmentDue>}
     */
    private function seedProjectWithInstallments(int $tenantId, int $count = 3): array
    {
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $projectId = $this->postJson('/api/tenant/donations/projects', [
            'name' => 'Quick Collect Project',
            'code' => 'QC-'.substr(uniqid(), -6),
            'assignment_mode' => 'uniform',
            'default_family_target' => 100 * $count,
            'installment_count' => $count,
            'installment_frequency' => 'monthly',
            'start_date' => now()->subMonths($count)->toDateString(),
            'status' => 'active',
            'auto_generate_installments' => false,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $installments = ProjectInstallmentDue::query()
            ->where('project_id', $projectId)
            ->where('family_id', $family->id)
            ->orderBy('installment_number')
            ->get();

        return [
            'project_id' => $projectId,
            'family' => $family,
            'installments' => $installments,
        ];
    }

    #[Test]
    public function strict_mode_records_multiple_installment_allocations_in_one_payment(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 3);
        $lines = $seed['installments']->take(2);
        $total = 0.0;
        $allocations = [];
        foreach ($lines as $line) {
            $remaining = (float) $line->amount_due - (float) $line->amount_paid;
            $total += $remaining;
            $allocations[] = [
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining,
            ];
        }

        $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($total, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => $allocations,
        ]))->assertCreated();

        $paymentId = $response->json('data.id');
        $this->assertSame(2, PaymentAllocation::where('payment_id', $paymentId)
            ->where('allocatable_type', 'project_installment')
            ->count());
        $this->assertSame(0, PaymentAllocation::where('payment_id', $paymentId)
            ->where('allocatable_type', 'advance')
            ->count());
    }

    #[Test]
    public function strict_mode_rejects_payment_amount_not_equal_to_allocation_sum(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 2);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due - (float) $line->amount_paid;

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($remaining + 50, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining,
            ]],
        ]))->assertStatus(422);

        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function strict_mode_rejects_duplicate_installment_ids(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due;

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($remaining * 2, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [
                [
                    'allocatable_type' => 'project_installment',
                    'allocatable_id' => $line->id,
                    'amount' => $remaining,
                ],
                [
                    'allocatable_type' => 'project_installment',
                    'allocatable_id' => $line->id,
                    'amount' => $remaining,
                ],
            ],
        ]))->assertStatus(422);

        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function strict_mode_rejects_stale_installment_balance(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due;

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '10.00', [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining,
            ]],
        ]))->assertStatus(422);
    }

    #[Test]
    public function collect_dues_endpoint_returns_project_summary_and_installment_page(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 3);

        $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/collect-dues')
            ->assertOk()
            ->assertJsonPath('data.summary.collectible_due', fn ($value) => $value > 0)
            ->assertJsonStructure(['data' => ['projects', 'summary', 'as_of_date']]);

        $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/collect-dues?'.http_build_query([
            'project_id' => $seed['project_id'],
            'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.installments.meta.total', 3)
            ->assertJsonCount(3, 'data.installments.data');
    }

    #[Test]
    public function idempotency_rejects_in_progress_replay_without_response_body(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $headers = ['Idempotency-Key' => 'qc-in-flight-'.uniqid()];

        \Modules\Donations\Models\DonationIdempotencyRecord::create([
            'tenant_id' => $ctx['tenant']->id,
            'operation' => 'payment.create',
            'key' => $headers['Idempotency-Key'],
            'payload_hash' => hash('sha256', json_encode($this->paymentPayload($family, '10.00'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'http_status' => 0,
        ]);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '10.00'), $headers)
            ->assertStatus(409);
    }

    #[Test]
    public function financial_profile_overdue_aggregate_counts_more_than_twenty_overdue_installments(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 25);

        $businessDate = DonationBusinessDate::today($ctx['tenant']->id);
        $expectedOverdue = ContributionBalance::sumFlooredOutstanding(
            ContributionBalance::scopeOverdue(
                ProjectInstallmentDue::forTenant($ctx['tenant']->id)
                    ->where('family_id', $seed['family']->id),
                $businessDate
            )
        );

        $profile = $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/financial-profile')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(25, (int) ($profile['totals']['overdue_count'] ?? 0));
        $this->assertEqualsWithDelta($expectedOverdue, (float) ($profile['totals']['overdue_amount'] ?? 0), 0.01);
        $this->assertLessThanOrEqual(20, count($profile['project_contributions']['outstanding_installments'] ?? []));
    }

    #[Test]
    public function strict_mode_rejects_second_payment_after_installment_is_fully_collected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due - (float) $line->amount_paid;

        $payload = $this->paymentPayload($seed['family'], number_format($remaining, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining,
            ]],
        ]);

        $this->postJson('/api/tenant/donations/payments', $payload)->assertCreated();

        $this->postJson('/api/tenant/donations/payments', $payload)->assertStatus(422);
        $this->assertSame(1, DonationPayment::query()->count());
    }

    #[Test]
    public function strict_mode_rejects_over_allocation_when_balance_already_reduced(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due;

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($remaining / 2, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining / 2,
            ]],
        ]))->assertCreated();

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($remaining, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $remaining,
            ]],
        ]))->assertStatus(422);
    }

    #[Test]
    public function strict_mode_concurrency_second_payment_rejected_after_first_reduces_balance(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due - (float) $line->amount_paid;
        $half = round($remaining / 2, 2);

        $allocation = static fn (float $amount) => [
            'collection_mode' => 'project_installments',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $line->id,
                'amount' => $amount,
            ]],
        ];

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($half, 2, '.', ''), $allocation($half)))
            ->assertCreated();
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($half, 2, '.', ''), $allocation($half)))
            ->assertCreated();

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($half, 2, '.', ''), $allocation($half)))
            ->assertStatus(422);

        $this->assertSame(2, DonationPayment::query()->count());
    }

    #[Test]
    public function strict_mode_rejects_due_allocation_in_project_installments_mode(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 1);
        $dueSeed = $this->seedDue($ctx['tenant']->id, $seed['family'], '100.00');
        $line = $seed['installments']->first();
        $remaining = (float) $line->amount_due - (float) $line->amount_paid;

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], number_format($remaining + 100, 2, '.', ''), [
            'collection_mode' => 'project_installments',
            'allocations' => [
                [
                    'allocatable_type' => 'project_installment',
                    'allocatable_id' => $line->id,
                    'amount' => $remaining,
                ],
                [
                    'allocatable_type' => 'due',
                    'allocatable_id' => $dueSeed['due']->id,
                    'amount' => 100,
                ],
            ],
        ]))->assertStatus(422);

        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function collect_dues_project_aggregate_independent_of_installment_page_size(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $seed = $this->seedProjectWithInstallments($ctx['tenant']->id, 25);

        $summary = $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/collect-dues')
            ->assertOk()
            ->json('data');

        $project = collect($summary['projects'] ?? [])->firstWhere('project_id', $seed['project_id']);
        $this->assertNotNull($project);
        $aggregateOutstanding = (float) ($project['installment_outstanding'] ?? 0);

        $page = $this->getJson('/api/tenant/donations/families/'.$seed['family']->id.'/collect-dues?'.http_build_query([
            'project_id' => $seed['project_id'],
            'per_page' => 5,
            'page' => 1,
        ]))->assertOk()->json('data');

        $this->assertSame(25, (int) ($page['installments']['meta']['total'] ?? 0));
        $this->assertCount(5, $page['installments']['data'] ?? []);
        $this->assertEqualsWithDelta($aggregateOutstanding, (float) ($project['installment_outstanding'] ?? 0), 0.01);
        $this->assertGreaterThan(0, $aggregateOutstanding);
    }

    #[Test]
    public function mandatory_dues_mode_records_multiple_due_allocations_in_one_payment(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $first = $this->seedDue($ctx['tenant']->id, null, '100.00');
        $second = $this->seedDue($ctx['tenant']->id, $first['family'], '150.00');

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($first['family'], '250.00', [
            'collection_mode' => 'mandatory_dues',
            'allocations' => [
                [
                    'allocatable_type' => 'due',
                    'allocatable_id' => $first['due']->id,
                    'amount' => 100,
                ],
                [
                    'allocatable_type' => 'due',
                    'allocatable_id' => $second['due']->id,
                    'amount' => 150,
                ],
            ],
        ]))->assertCreated();

        $this->assertSame('100.00', number_format((float) ContributionDue::find($first['due']->id)->amount_paid, 2, '.', ''));
        $this->assertSame('150.00', number_format((float) ContributionDue::find($second['due']->id)->amount_paid, 2, '.', ''));
        $this->assertSame(0, PaymentAllocation::query()->where('allocatable_type', 'advance')->count());
    }
}
