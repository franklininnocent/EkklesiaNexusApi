<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DonationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $tenantAdminUser;

    protected Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);

        $this->tenantAdminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantAdminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);
        $this->tenantAdminUser->syncRoles([$this->tenantAdminRole->id]);

        $permissionNames = [
            'donations.view',
            'donations.manage',
            'donations.collect',
            'donations.reverse',
            'donations.refund',
            'donations.reports',
            'donations.approvals',
            'donations.notifications',
            'church.settings.edit',
        ];

        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test permission',
                    'module' => 'Donations',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'donations',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $this->tenantAdminRole->permissions()->syncWithoutDetaching($permissionIds);

        Passport::actingAs($this->tenantAdminUser);
    }

    #[Test]
    public function it_creates_a_fund_and_lists_it(): void
    {
        $create = $this->postJson('/api/tenant/donations/funds', [
            'name' => 'Sunday Offering',
            'code' => 'SUN_OFFER',
            'status' => 'active',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Sunday Offering');

        $list = $this->getJson('/api/tenant/donations/funds');
        $list->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_creates_payment_with_due_allocation_and_updates_dashboard(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Tithe',
            'code' => 'TITHE',
            'frequency' => 'monthly',
            'default_amount' => 1000,
            'status' => 'active',
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-06',
            'due_date' => now()->toDateString(),
            'amount_due' => 1000,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'John Family',
            'payment_date' => now()->toDateString(),
            'amount' => 1000,
            'method' => 'cash',
            'allocations' => [
                [
                    'allocatable_type' => 'due',
                    'allocatable_id' => $due->id,
                    'amount' => 1000,
                ],
            ],
        ]);

        $payment->assertCreated()
            ->assertJsonPath('data.status', 'succeeded');

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary');
        $dashboard->assertOk()
            ->assertJsonPath('data.totals.collected', 1000);
    }

    #[Test]
    public function it_blocks_access_when_donations_feature_is_disabled(): void
    {
        $this->tenant->update(['features' => ['families']]);

        $response = $this->getJson('/api/tenant/donations/dashboard/summary');
        $response->assertStatus(403)
            ->assertJsonPath('message', 'Donations feature is not enabled for this tenant.');
    }

    #[Test]
    public function it_creates_donation_entry_and_settings(): void
    {
        $settings = $this->putJson('/api/tenant/donations/settings', [
            'financial_year_start_month' => '04',
            'financial_year_start_day' => '01',
            'tax_registration_number' => 'TAX-123',
            'receipt_prefix_enabled' => true,
            'receipt_prefix' => 'RCPT',
            'metadata' => [],
        ]);
        $settings->assertOk()->assertJsonPath('data.default_currency', 'INR');

        $entry = $this->postJson('/api/tenant/donations/entries', [
            'title' => 'Open Donation',
            'pledged_amount' => 2500,
            'status' => 'pledged',
        ]);
        $entry->assertCreated()->assertJsonPath('data.title', 'Open Donation');
    }

    #[Test]
    public function it_generates_dues_for_selected_families(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN2',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Quarterly Plan',
            'code' => 'QPLAN',
            'plan_type' => 'uniform',
            'frequency' => 'quarterly',
            'default_amount' => 750,
            'status' => 'active',
        ]);

        $familyOne = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $familyTwo = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
            'family_ids' => [$familyOne->id, $familyTwo->id],
            'period_label' => '2026-Q3',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('contribution_dues', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'family_id' => $familyOne->id,
            'period_label' => '2026-Q3',
        ]);
        $this->assertDatabaseHas('contribution_dues', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'family_id' => $familyTwo->id,
            'period_label' => '2026-Q3',
        ]);
    }

    #[Test]
    public function it_creates_uniform_and_individual_contribution_plans(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parish Fund',
            'code' => 'PARISH',
            'status' => 'active',
        ]);

        $uniform = $this->postJson('/api/tenant/donations/plans', [
            'fund_id' => $fund->id,
            'name' => 'Monthly Parish',
            'code' => 'MONTHLY_PARISH',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'start_date' => now()->toDateString(),
            'auto_generate' => true,
            'status' => 'active',
        ]);
        $uniform->assertCreated()->assertJsonPath('data.plan_type', 'uniform');

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $individual = $this->postJson('/api/tenant/donations/plans', [
            'fund_id' => $fund->id,
            'name' => 'Custom Family Plan',
            'code' => 'CUSTOM_FAMILY',
            'plan_type' => 'individual',
            'frequency' => 'yearly',
            'default_amount' => 1,
            'start_date' => now()->toDateString(),
            'assignments' => [
                [
                    'family_id' => $family->id,
                    'amount' => 2000,
                    'effective_from' => now()->toDateString(),
                ],
            ],
            'status' => 'active',
        ]);
        $individual->assertCreated()->assertJsonPath('data.plan_type', 'individual');

        $oneTime = $this->postJson('/api/tenant/donations/plans', [
            'fund_id' => $fund->id,
            'name' => 'Festival Donation - 2026',
            'code' => 'FESTIVAL_2026',
            'plan_type' => 'uniform',
            'frequency' => 'one_time',
            'default_amount' => 500,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'auto_generate' => true,
            'status' => 'active',
        ]);
        $oneTime->assertCreated()->assertJsonPath('data.frequency', 'one_time');

        $this->assertDatabaseHas('contribution_plans', [
            'tenant_id' => $this->tenant->id,
            'code' => 'FESTIVAL_2026',
            'frequency' => 'one_time',
        ]);

        $this->assertDatabaseHas('contribution_plan_assignments', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $individual->json('data.id'),
            'family_id' => $family->id,
            'amount' => 2000,
        ]);
    }

    #[Test]
    public function it_rejects_individual_contribution_plan_without_family_assignments(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parish Fund',
            'code' => 'PARISH_NO_ASSIGN',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/tenant/donations/plans', [
            'fund_id' => $fund->id,
            'name' => 'Empty Individual Plan',
            'code' => 'EMPTY_INDIVIDUAL',
            'plan_type' => 'individual',
            'frequency' => 'monthly',
            'default_amount' => 1,
            'start_date' => now()->toDateString(),
            'assignments' => [],
            'status' => 'active',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['assignments']);
    }

    #[Test]
    public function it_generates_current_period_dues_for_individual_plan_amounts(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F1',
            'status' => 'active',
        ]);

        $familyA = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyB = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Individual Monthly',
            'code' => 'IND_MONTH',
            'plan_type' => 'individual',
            'frequency' => 'monthly',
            'default_amount' => 1,
            'status' => 'active',
        ]);

        $this->postJson("/api/tenant/donations/plans/{$plan->id}/assignments", [
            'family_id' => $familyA->id,
            'amount' => 50,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/plans/{$plan->id}/assignments", [
            'family_id' => $familyB->id,
            'amount' => 100,
            'effective_from' => now()->startOfMonth()->toDateString(),
        ])->assertCreated();

        $response = $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
            'use_current_period' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('contribution_dues', [
            'plan_id' => $plan->id,
            'family_id' => $familyA->id,
            'amount_due' => 50,
        ]);
        $this->assertDatabaseHas('contribution_dues', [
            'plan_id' => $plan->id,
            'family_id' => $familyB->id,
            'amount_due' => 100,
        ]);
    }

    #[Test]
    public function it_calculates_outstanding_pending_dues_after_partial_payment(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F2',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 1000,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-06',
            'due_date' => now()->toDateString(),
            'amount_due' => 1000,
            'amount_paid' => 400,
            'status' => 'partially_paid',
        ]);

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary');
        $dashboard->assertOk()->assertJsonPath('data.totals.pending_dues', 600);

        $profile = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");
        $profile->assertOk()->assertJsonPath('data.totals.pending_due', 600);
    }

    #[Test]
    public function it_waives_and_cancels_pending_dues(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F3',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON2',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-08',
            'due_date' => now()->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson("/api/tenant/donations/dues/{$due->id}/waive", ['reason' => 'Hardship'])
            ->assertOk()
            ->assertJsonPath('data.status', 'waived');

        $dueTwo = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-09',
            'due_date' => now()->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson("/api/tenant/donations/dues/{$dueTwo->id}/cancel", ['reason' => 'Moved out'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    #[Test]
    public function it_returns_family_financial_profile(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN3',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Plan',
            'code' => 'MPLAN',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-07',
            'due_date' => now()->addDays(15)->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Household',
            'payment_date' => now()->toDateString(),
            'amount' => 200,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");
        $response->assertOk()
            ->assertJsonPath('data.family_id', $family->id);
    }

    #[Test]
    public function it_creates_payment_batch_with_tenant_payments(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Batch Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 120,
            'method' => 'cash',
        ]);
        $payment->assertCreated();
        $paymentId = $payment->json('data.id');

        $batch = $this->postJson('/api/tenant/donations/payment-batches', [
            'batch_date' => now()->toDateString(),
            'payment_ids' => [$paymentId],
        ]);

        $batch->assertCreated()->assertJsonPath('data.payments_count', 1);
    }

    #[Test]
    public function it_creates_recurring_donation_schedule(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson('/api/tenant/donations/recurring-schedules', [
            'family_id' => $family->id,
            'amount' => 250,
            'frequency' => 'monthly',
            'next_run_on' => now()->addMonth()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.frequency', 'monthly');
    }

    #[Test]
    public function it_supports_payment_batch_upload_preview_and_commit(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $preview = $this->postJson('/api/tenant/donations/payment-batches/upload', [
            'batch_date' => now()->toDateString(),
            'rows' => [
                [
                    'family_id' => $family->id,
                    'payer_name' => 'Upload Payer',
                    'payment_date' => now()->toDateString(),
                    'amount' => 90,
                    'method' => 'cash',
                ],
            ],
        ]);
        $preview->assertOk()->assertJsonPath('data.rows_count', 1);

        $commit = $this->postJson('/api/tenant/donations/payment-batches/upload', [
            'commit' => true,
            'batch_date' => now()->toDateString(),
            'rows' => [
                [
                    'family_id' => $family->id,
                    'payer_name' => 'Upload Payer',
                    'payment_date' => now()->toDateString(),
                    'amount' => 90,
                    'method' => 'cash',
                ],
            ],
        ]);
        $commit->assertCreated()->assertJsonPath('data.batch.payments_count', 1);
    }

    #[Test]
    public function it_runs_due_recurring_schedules(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->postJson('/api/tenant/donations/recurring-schedules', [
            'family_id' => $family->id,
            'amount' => 250,
            'frequency' => 'monthly',
            'next_run_on' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $run = $this->postJson('/api/tenant/donations/recurring-schedules/run-due');
        $run->assertOk()->assertJsonPath('data.processed', 1);
    }

    #[Test]
    public function it_accepts_webhook_with_valid_signature(): void
    {
        config([
            'donations.webhooks.secret' => 'test-secret',
            'donations.webhooks.providers.generic.secret' => 'test-secret',
        ]);

        $response = $this->withHeaders([
            'X-Payment-Signature' => 'test-secret',
        ])->postJson('/api/donations/webhooks/generic', [
            'id' => 'evt_1',
            'type' => 'payment.succeeded',
            'data' => ['amount' => 100],
        ]);

        $response->assertStatus(202)->assertJsonPath('success', true);
    }

    #[Test]
    public function it_creates_uniform_project_with_installments_for_all_families(): void
    {
        $familyOne = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyTwo = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $response = $this->postJson('/api/tenant/donations/projects', [
            'name' => 'Church Construction',
            'code' => 'CHURCH_BUILD',
            'assignment_mode' => 'uniform',
            'default_family_target' => 50000,
            'target_amount' => 100000,
            'installment_count' => 5,
            'installment_frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'auto_generate_installments' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.assignment_mode', 'uniform');
        $projectId = $response->json('data.id');

        $this->assertDatabaseHas('project_installment_dues', [
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId,
            'family_id' => $familyOne->id,
            'installment_number' => 1,
            'amount_due' => 10000,
        ]);
        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyTwo->id,
            'installment_number' => 5,
        ]);
    }

    #[Test]
    public function it_creates_individual_project_with_family_specific_targets(): void
    {
        $familyA = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyB = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $response = $this->postJson('/api/tenant/donations/projects', [
            'name' => 'Parish Hall',
            'code' => 'PARISH_HALL',
            'assignment_mode' => 'individual',
            'default_family_target' => 0,
            'installment_count' => 2,
            'status' => 'active',
            'auto_generate_installments' => false,
            'assignments' => [
                ['family_id' => $familyA->id, 'target_amount' => 25000, 'effective_from' => now()->toDateString()],
                ['family_id' => $familyB->id, 'target_amount' => 50000, 'effective_from' => now()->toDateString()],
            ],
        ]);

        $response->assertCreated();
        $projectId = $response->json('data.id');

        $generate = $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments");
        $generate->assertOk()
            ->assertJsonPath('data.created', 4)
            ->assertJsonPath('data.families', 2)
            ->assertJsonPath('data.installments', 4);

        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyA->id,
            'amount_due' => 12500,
        ]);
        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyB->id,
            'amount_due' => 25000,
        ]);
    }

    #[Test]
    public function it_supports_uniform_project_with_exceptions_and_exemptions(): void
    {
        $familyDefault = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyX = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyY = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $familyZ = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $project = $this->postJson('/api/tenant/donations/projects', [
            'name' => 'Cemetery Development',
            'code' => 'CEMETERY',
            'assignment_mode' => 'uniform_with_exceptions',
            'default_family_target' => 50000,
            'installment_count' => 1,
            'status' => 'active',
            'auto_generate_installments' => false,
        ]);
        $project->assertCreated();
        $projectId = $project->json('data.id');

        $this->postJson("/api/tenant/donations/projects/{$projectId}/assignments", [
            'family_id' => $familyX->id,
            'target_amount' => 20000,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/projects/{$projectId}/assignments", [
            'family_id' => $familyY->id,
            'target_amount' => 10000,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/projects/{$projectId}/assignments", [
            'family_id' => $familyZ->id,
            'is_exempt' => true,
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyDefault->id,
            'amount_due' => 50000,
        ]);
        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyX->id,
            'amount_due' => 20000,
        ]);
        $this->assertDatabaseMissing('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $familyZ->id,
        ]);
    }

    #[Test]
    public function it_returns_project_financial_dashboard_and_records_installment_payments(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $project = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Welfare Project',
            'code' => 'WELFARE',
            'assignment_mode' => 'uniform',
            'default_family_target' => 10000,
            'target_amount' => 10000,
            'installment_count' => 2,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        $generate = $this->postJson("/api/tenant/donations/projects/{$project->id}/generate-installments");
        $generate->assertOk();

        $installment = ProjectInstallmentDue::where('project_id', $project->id)
            ->where('family_id', $family->id)
            ->orderBy('installment_number')
            ->firstOrFail();

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Project Donor',
            'payment_date' => now()->toDateString(),
            'amount' => 5000,
            'method' => 'cash',
            'allocations' => [
                [
                    'allocatable_type' => 'project_installment',
                    'allocatable_id' => $installment->id,
                    'amount' => 5000,
                ],
            ],
        ])->assertCreated();

        $dashboard = $this->getJson("/api/tenant/donations/projects/{$project->id}/dashboard");
        $dashboard->assertOk()
            ->assertJsonPath('data.totals.collected', 5000)
            ->assertJsonPath('data.families.enrolled', 1)
            ->assertJsonPath('data.families.partial', 1);

        $installment->refresh();
        $this->assertSame('paid', $installment->status);
    }

    #[Test]
    public function it_includes_project_contributions_in_family_financial_profile(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $project = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Education Fund',
            'code' => 'EDU',
            'assignment_mode' => 'uniform',
            'default_family_target' => 20000,
            'target_amount' => 20000,
            'installment_count' => 2,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        $this->postJson("/api/tenant/donations/projects/{$project->id}/generate-installments")->assertOk();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");
        $response->assertOk()
            ->assertJsonPath('data.totals.pending_project_due', 20000)
            ->assertJsonCount(1, 'data.project_contributions.projects')
            ->assertJsonPath('data.project_contributions.projects.0.project_name', 'Education Fund');
    }

    #[Test]
    public function it_collects_voluntary_donation_with_payment_and_receipt(): void
    {
        $category = $this->postJson('/api/tenant/donations/categories', [
            'name' => 'Thanksgiving Offering',
            'code' => 'THANKS',
            'is_tax_deductible' => true,
        ])->assertCreated()->json('data');

        $this->putJson('/api/tenant/donations/settings', [
            'financial_year_start_month' => '04',
            'financial_year_start_day' => '01',
            'tax_registration_number' => 'TAX-999',
            'tax_acknowledgement_note' => 'Thank you for your tax-deductible gift.',
            'receipt_prefix_enabled' => true,
            'receipt_prefix' => 'SHC',
            'metadata' => [],
        ])->assertOk();

        $response = $this->postJson('/api/tenant/donations/entries/collect', [
            'donation_category_id' => $category['id'],
            'title' => 'Thanksgiving Offering',
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_type' => 'external',
            'amount' => 1500,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.donation.status', 'paid')
            ->assertJsonPath('data.donation.collected_amount', '1500.00')
            ->assertJsonPath('data.payment.amount', '1500.00');

        $paymentId = $response->json('data.payment.id');
        $receipt = $this->getJson("/api/tenant/donations/payments/{$paymentId}/receipt");
        $receipt->assertOk()
            ->assertJsonPath('data.tax_acknowledgement.registration_number', 'TAX-999')
            ->assertJsonPath('data.tax_acknowledgement.tax_deductible_amount', 1500)
            ->assertJsonPath('data.payer.name', 'Jane Doe');
    }

    #[Test]
    public function it_supports_anonymous_voluntary_donation(): void
    {
        $response = $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Anonymous Donation',
            'is_anonymous' => true,
            'amount' => 500,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.donation.is_anonymous', true)
            ->assertJsonPath('data.payment.is_anonymous', true);

        $paymentId = $response->json('data.payment.id');
        $receipt = $this->getJson("/api/tenant/donations/payments/{$paymentId}/receipt");
        $receipt->assertOk()
            ->assertJsonPath('data.payer.name', 'Anonymous Donor')
            ->assertJsonPath('data.payer.email', null);
    }

    #[Test]
    public function it_applies_donation_allocation_when_paying_existing_entry(): void
    {
        $entry = $this->postJson('/api/tenant/donations/entries', [
            'title' => 'Memorial Donation',
            'pledged_amount' => 2000,
            'status' => 'pledged',
        ])->assertCreated()->json('data');

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'payer_name' => 'Donor',
            'payment_date' => now()->toDateString(),
            'amount' => 800,
            'method' => 'cash',
            'allocations' => [
                [
                    'allocatable_type' => 'donation',
                    'allocatable_id' => $entry['id'],
                    'amount' => 800,
                ],
            ],
        ]);

        $payment->assertCreated();

        $show = $this->getJson("/api/tenant/donations/entries/{$entry['id']}");
        $show->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.collected_amount', '800.00');
    }

    #[Test]
    public function it_runs_recurring_schedule_with_donation_allocation(): void
    {
        $category = $this->postJson('/api/tenant/donations/categories', [
            'name' => 'General Church Donation',
            'code' => 'GENERAL',
        ])->assertCreated()->json('data');

        $donor = $this->postJson('/api/tenant/donations/donors', [
            'name' => 'Monthly Giver',
            'donor_type' => 'individual',
            'email' => 'monthly@example.com',
        ])->assertCreated()->json('data');

        $schedule = $this->postJson('/api/tenant/donations/recurring-schedules', [
            'donor_id' => $donor['id'],
            'donation_category_id' => $category['id'],
            'amount' => 1000,
            'frequency' => 'monthly',
            'next_run_on' => now()->toDateString(),
        ])->assertCreated()->json('data');

        $run = $this->postJson('/api/tenant/donations/recurring-schedules/run-due');
        $run->assertOk()->assertJsonPath('data.succeeded', 1);

        $entries = $this->getJson('/api/tenant/donations/entries');
        $entries->assertOk();
        $this->assertGreaterThanOrEqual(1, count($entries->json('data.data')));
    }

    #[Test]
    public function it_lists_donation_audit_history(): void
    {
        $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Charity Donation',
            'donor_name' => 'Audit Test Donor',
            'amount' => 100,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $logs = $this->getJson('/api/tenant/donations/audit-logs?target_type=donation');
        $logs->assertOk();
        $this->assertGreaterThanOrEqual(1, count($logs->json('data.data')));
        $first = $logs->json('data.data.0');
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertArrayHasKey('actor_name', $first);
        $this->assertArrayNotHasKey('event', $first);
        $this->assertArrayNotHasKey('target_id', $first);
    }

    #[Test]
    public function it_exports_donation_entries_csv(): void
    {
        $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Building Fund Donation',
            'donor_name' => 'Builder Bob',
            'amount' => 5000,
            'method' => 'bank_transfer',
            'gateway_reference' => 'NEFT-5000',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $export = $this->postJson('/api/tenant/donations/reports/export', [
            'report_type' => 'donation_entries',
        ]);

        $export->assertCreated()->assertJsonPath('data.report_type', 'donation_entries');
    }

    #[Test]
    public function it_includes_voluntary_metrics_in_dashboard_summary(): void
    {
        $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'General Offering',
            'donor_name' => 'Giver',
            'amount' => 300,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary');
        $dashboard->assertOk()
            ->assertJsonPath('data.totals.voluntary_collected', 300)
            ->assertJsonPath('data.totals.voluntary_entries', 1);
    }

    #[Test]
    public function it_caps_dashboard_collections_at_business_today_and_flags_future_dated_payments(): void
    {
        $this->tenant->update([
            'settings' => ['timezone' => 'UTC', 'language' => 'en', 'currency' => 'INR'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'UTC'));

        DonationSetting::create([
            'tenant_id' => $this->tenant->id,
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'default_currency' => 'INR',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-TODAY',
            'payer_name' => 'Today Payer',
            'payment_date' => Carbon::now()->toDateString(),
            'amount' => 400,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FUTURE',
            'payer_name' => 'Future Payer',
            'payment_date' => Carbon::now()->addMonths(2)->toDateString(),
            'amount' => 250,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary');
        $dashboard->assertOk()
            ->assertJsonPath('data.totals.collected', 400)
            ->assertJsonPath('data.totals.future_dated_payments.count', 1)
            ->assertJsonPath('data.totals.future_dated_payments.amount', 250)
            ->assertJsonPath('data.period_collections.current_month_collected', 400)
            ->assertJsonPath('data.period_collections.annual_collected', 400);

        $this->assertSame(Carbon::now()->toDateString(), $dashboard->json('data.recent_activity.0.date'));
    }

    #[Test]
    public function it_returns_executive_dashboard_insights(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Dashboard Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 500,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson('/api/tenant/donations/dashboard/summary');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'financial_health' => ['score', 'label', 'status', 'summary'],
                    'families' => ['total', 'active', 'participation_rate'],
                    'period_collections' => ['current_month_collected', 'annual_collected'],
                    'collection_trend',
                    'families_requiring_attention',
                    'recent_activity',
                    'active_project_summaries',
                ],
            ])
            ->assertJsonPath('data.families.active', 1);

        $this->assertCount(12, $response->json('data.collection_trend'));
    }

    #[Test]
    public function it_returns_diocese_rollup_dashboard_for_child_parishes(): void
    {
        $this->tenant->update([
            'tenant_tier' => 'diocese',
            'name' => 'Test Diocese',
        ]);
        $this->tenant->refresh();

        $parish = Tenant::factory()->create([
            'parent_tenant_id' => $this->tenant->id,
            'tenant_tier' => 'parish',
            'name' => 'St Mary Parish',
            'features' => ['donations'],
        ]);
        $parish->refresh();

        $family = Family::factory()->create(['tenant_id' => $parish->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $parish->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARISH-001',
            'payer_name' => 'Parish Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 900,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/rollup');
        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.scope.parish_count', 1)
            ->assertJsonStructure([
                'data' => [
                    'available',
                    'root',
                    'consolidated' => ['total_collected', 'pending_dues', 'current_month_collected'],
                    'parishes',
                    'collection_trend',
                ],
            ]);

        $this->assertGreaterThanOrEqual(900, (float) $response->json('data.consolidated.total_collected'));
    }

    #[Test]
    public function it_returns_rollup_with_money_comparable_false_for_mixed_parish_currencies(): void
    {
        $india = Country::query()->where('iso2', 'IN')->first()
            ?? Country::factory()->create(['iso2' => 'IN', 'name' => 'India', 'currency' => 'INR']);
        $usa = Country::query()->where('iso2', 'US')->first()
            ?? Country::factory()->create(['iso2' => 'US', 'name' => 'United States', 'currency' => 'USD']);

        $this->tenant->update([
            'tenant_tier' => 'diocese',
            'name' => 'Mixed Currency Diocese',
        ]);
        $this->tenant->refresh();

        $parishInr = Tenant::factory()->create([
            'parent_tenant_id' => $this->tenant->id,
            'tenant_tier' => 'parish',
            'name' => 'Parish India',
            'features' => ['donations'],
        ]);
        $parishUsd = Tenant::factory()->create([
            'parent_tenant_id' => $this->tenant->id,
            'tenant_tier' => 'parish',
            'name' => 'Parish USA',
            'features' => ['donations'],
        ]);

        $this->seedOfficialAddressForTenant($parishInr, $india);
        $this->seedOfficialAddressForTenant($parishUsd, $usa);

        $resolver = app(ChurchCurrencyResolver::class);
        $resolver->syncDerivedColumns((int) $parishInr->id);
        $resolver->syncDerivedColumns((int) $parishUsd->id);

        $familyInr = Family::factory()->create(['tenant_id' => $parishInr->id, 'status' => 'active']);
        $familyUsd = Family::factory()->create(['tenant_id' => $parishUsd->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $parishInr->id,
            'family_id' => $familyInr->id,
            'payment_number' => 'PAY-INR-001',
            'payer_name' => 'INR Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 500,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        DonationPayment::create([
            'tenant_id' => $parishUsd->id,
            'family_id' => $familyUsd->id,
            'payment_number' => 'PAY-USD-001',
            'payer_name' => 'USD Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/rollup');

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.money_comparable', false)
            ->assertJsonPath('data.consolidated.total_collected', null)
            ->assertJsonPath('data.consolidated.pending_dues', null)
            ->assertJsonPath('data.consolidated.current_month_collected', null);

        $currencies = $response->json('data.currencies') ?? [];
        sort($currencies);
        $expected = ['INR', 'USD'];
        sort($expected);
        $this->assertSame($expected, $currencies);

        $parishes = collect($response->json('data.parishes') ?? []);
        $inrRow = $parishes->firstWhere('tenant_id', $parishInr->id);
        $usdRow = $parishes->firstWhere('tenant_id', $parishUsd->id);
        $this->assertNotNull($inrRow);
        $this->assertNotNull($usdRow);
        $this->assertGreaterThanOrEqual(500, (float) ($inrRow['metrics']['total_collected'] ?? 0));
        $this->assertGreaterThanOrEqual(100, (float) ($usdRow['metrics']['total_collected'] ?? 0));
    }

    #[Test]
    public function it_builds_upi_payment_intent_with_qr_code(): void
    {
        DonationSetting::create([
            'tenant_id' => $this->tenant->id,
            'default_currency' => 'INR',
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'metadata' => [
                'upi_vpa' => 'church@upi',
                'upi_payee_name' => 'Test Church',
            ],
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $response = $this->getJson('/api/tenant/donations/upi/intent?amount=500&family_id='.$family->id);
        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.vpa', 'church@upi')
            ->assertJsonPath('data.amount', 500)
            ->assertJsonStructure([
                'data' => ['available', 'upi_uri', 'qr_data_uri', 'vpa', 'payee_name', 'amount', 'currency'],
            ]);

        $this->assertStringStartsWith('upi://pay?', $response->json('data.upi_uri'));
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('data.qr_data_uri'));
    }

    #[Test]
    public function it_lists_receipts_in_receipts_hub(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Receipt Hub Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 250,
            'method' => 'cash',
        ])->assertCreated();

        $paymentId = $payment->json('data.id');
        $this->getJson("/api/tenant/donations/payments/{$paymentId}/receipt")->assertOk();

        $response = $this->getJson('/api/tenant/donations/receipts?search=Receipt');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        ['id', 'receipt_number', 'issued_on', 'payment_id', 'payer_name', 'amount'],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.data'));
    }

    #[Test]
    public function it_processes_financial_ai_prompt_for_overdue_families(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'AI-FUND',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Plan',
            'code' => 'AI-PLAN',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-04',
            'due_date' => now()->subDays(20)->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/tenant/donations/ai/ask', [
            'prompt' => 'Show families with outstanding contributions',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'overdue_families')
            ->assertJsonPath('data.engine', 'semantic_v2')
            ->assertJsonStructure([
                'data' => ['answer', 'families', 'recommended_actions', 'match_score'],
            ]);
    }

    #[Test]
    public function it_processes_semantic_forecast_prompt(): void
    {
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'payment_number' => 'FCST-001',
            'payer_name' => 'Forecast Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 1200,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->postJson('/api/tenant/donations/ai/ask', [
            'prompt' => 'Forecast collections for the next 3 months',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'collection_forecast')
            ->assertJsonPath('data.engine', 'semantic_v2')
            ->assertJsonStructure(['data' => ['forecast', 'answer']]);
    }

    #[Test]
    public function it_returns_collection_forecast_dashboard_data(): void
    {
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'payment_number' => 'FCST-002',
            'payer_name' => 'Forecast Payer 2',
            'payment_date' => now()->toDateString(),
            'amount' => 800,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/forecast?months=3');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['method', 'history', 'signals', 'projections', 'narrative'],
            ]);
    }

    #[Test]
    public function it_parses_receipt_ocr_from_extracted_text(): void
    {
        $response = $this->postJson('/api/tenant/donations/payments/ocr-scan', [
            'extracted_text' => 'Receipt Total Amount: Rs. 1,250.00 Paid by John Doe on 2026-06-10 via UPI',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.suggested_amount', 1250)
            ->assertJsonPath('data.suggested_method', 'upi');
    }

    #[Test]
    public function it_previews_whatsapp_outreach_targets(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Outreach Fund',
            'code' => 'OUT-FUND',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Annual Plan',
            'code' => 'OUT-PLAN',
            'frequency' => 'yearly',
            'default_amount' => 1000,
            'status' => 'active',
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'head_of_family' => 'Outreach Head',
        ]);

        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Outreach',
            'last_name' => 'Head',
            'relationship_to_head' => 'self',
            'phone' => '9876543210',
            'is_primary_contact' => true,
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2025',
            'due_date' => now()->subDays(10)->toDateString(),
            'amount_due' => 1000,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/tenant/donations/outreach/whatsapp/preview');
        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.eligible_count', 1)
            ->assertJsonStructure(['data' => ['targets', 'template']]);

        $this->assertStringContainsString('wa.me/', $response->json('data.targets.0.whatsapp_url'));
    }

    #[Test]
    public function it_seeds_default_donation_categories(): void
    {
        $response = $this->postJson('/api/tenant/donations/categories/seed-defaults');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(7, count($response->json('data')));
    }

    #[Test]
    public function it_updates_and_deletes_unused_donation_categories(): void
    {
        $create = $this->postJson('/api/tenant/donations/categories', [
            'name' => 'Special Offering',
            'code' => 'SPECIAL',
            'is_tax_deductible' => true,
            'active' => true,
        ]);
        $create->assertCreated();
        $categoryId = $create->json('data.id');

        $this->putJson("/api/tenant/donations/categories/{$categoryId}", [
            'name' => 'Special Offering Updated',
            'is_tax_deductible' => false,
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Special Offering Updated')
            ->assertJsonPath('data.active', false);

        $this->deleteJson("/api/tenant/donations/categories/{$categoryId}")
            ->assertOk();

        $this->assertSoftDeleted('donation_categories', [
            'id' => $categoryId,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function it_blocks_deleting_donation_categories_in_use(): void
    {
        $category = $this->postJson('/api/tenant/donations/categories', [
            'name' => 'Used Category',
            'code' => 'USED',
            'active' => true,
        ])->assertCreated()->json('data');

        $this->postJson('/api/tenant/donations/entries/collect', [
            'donation_category_id' => $category['id'],
            'title' => 'Used category gift',
            'donor_name' => 'Donor',
            'amount' => 100,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $this->deleteJson("/api/tenant/donations/categories/{$category['id']}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_includes_voluntary_donations_in_family_financial_profile(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/entries/collect', [
            'family_id' => $family->id,
            'title' => 'Family Thanksgiving Offering',
            'donor_name' => 'Family Donor',
            'donor_type' => 'family',
            'amount' => 750,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");
        $response->assertOk()
            ->assertJsonPath('data.totals.voluntary_collected', 750)
            ->assertJsonCount(1, 'data.voluntary_donations.recent_donations');
    }

    #[Test]
    public function it_returns_complete_family_contribution_profile_dashboard(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN-DASH',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Tithe',
            'code' => 'TITHE-DASH',
            'frequency' => 'monthly',
            'default_amount' => 1000,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $otherFamily = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-05',
            'due_date' => now()->subDays(10)->toDateString(),
            'amount_due' => 1000,
            'amount_paid' => 400,
            'status' => 'partially_paid',
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-06',
            'due_date' => now()->subDays(2)->toDateString(),
            'amount_due' => 1000,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $project = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Building Fund',
            'code' => 'BLDG',
            'assignment_mode' => 'uniform',
            'default_family_target' => 5000,
            'target_amount' => 5000,
            'installment_count' => 1,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        $this->postJson("/api/tenant/donations/projects/{$project->id}/generate-installments")->assertOk();

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Primary Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 900,
            'method' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/tenant/donations/entries/collect', [
            'family_id' => $family->id,
            'title' => 'Thanksgiving Offering',
            'donor_name' => 'Family Donor',
            'donor_type' => 'family',
            'amount' => 300,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $otherFamily->id,
            'payer_name' => 'Other Family',
            'payment_date' => now()->toDateString(),
            'amount' => 50,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'family_id',
                    'financial_year',
                    'totals' => [
                        'total_paid',
                        'mandatory_paid',
                        'project_paid',
                        'voluntary_paid',
                        'pending_due',
                        'pending_mandatory_due',
                        'pending_project_due',
                        'overdue_count',
                        'overdue_amount',
                    ],
                    'mandatory_contributions' => [
                        'totals' => ['assigned', 'paid', 'pending', 'overdue_amount', 'overdue_count'],
                        'plan_summaries',
                        'outstanding_dues',
                    ],
                    'project_contributions' => [
                        'projects',
                        'installment_ledger',
                        'totals',
                    ],
                    'donations_offerings' => [
                        'lifetime_collected',
                        'current_financial_year_collected',
                        'last_donation_date',
                        'by_category',
                        'recent_donations',
                    ],
                    'payment_history' => [
                        'ledger',
                        'recent',
                        'total_transactions',
                    ],
                    'outstanding_balances' => [
                        'mandatory',
                        'mandatory_overdue',
                        'project',
                        'project_installments',
                        'voluntary_pledged',
                        'total',
                    ],
                    'analytics' => [
                        'trend',
                        'punctuality' => ['score', 'label', 'evaluated_periods', 'paid_on_time', 'overdue_open'],
                        'ranking' => ['by_total_giving', 'participating_families', 'percentile', 'total_paid'],
                        'comparison' => [
                            'tenant_average_giving',
                            'tenant_median_giving',
                            'family_total_giving',
                            'vs_average_pct',
                            'vs_median_pct',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.mandatory_contributions.totals.assigned', 2000)
            ->assertJsonPath('data.mandatory_contributions.totals.paid', 400)
            ->assertJsonPath('data.mandatory_contributions.totals.pending', 1600)
            ->assertJsonPath('data.donations_offerings.lifetime_collected', 300)
            ->assertJsonPath('data.totals.voluntary_collected', 300)
            ->assertJsonPath('data.payment_history.total_transactions', 2)
            ->assertJsonPath('data.analytics.ranking.by_total_giving', 1)
            ->assertJsonPath('data.analytics.punctuality.evaluated_periods', 2);

        $this->assertNotNull($response->json('data.donations_offerings.last_donation_date'));
        $this->assertCount(12, $response->json('data.analytics.trend'));
        $this->assertNotEmpty($response->json('data.payment_history.ledger'));
        $this->assertNotNull($response->json('data.payment_history.ledger.0.payment_number'));
        $response->assertJsonStructure([
            'data' => [
                'financial_health' => ['score', 'label', 'status', 'factors'],
                'financial_timeline',
                'ai_insights',
                'recommended_actions',
            ],
        ]);
    }

    #[Test]
    public function it_includes_assigned_contribution_plans_before_dues_are_generated(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parish Fund',
            'code' => 'PARISH',
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $uniformPlan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Parish Dues',
            'code' => 'MONTHLY_PARISH',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $individualPlan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Custom Family Plan',
            'code' => 'CUSTOM_FAMILY',
            'plan_type' => 'individual',
            'frequency' => 'yearly',
            'default_amount' => 1,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        ContributionPlanAssignment::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $individualPlan->id,
            'family_id' => $family->id,
            'amount' => 2000,
            'effective_from' => now()->toDateString(),
            'status' => 'active',
            'is_exempt' => false,
            'created_by' => $this->tenantAdminUser->id,
            'updated_by' => $this->tenantAdminUser->id,
        ]);

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");

        $response->assertOk()
            ->assertJsonPath('data.mandatory_contributions.contribution_plan_totals.active_plans_count', 2)
            ->assertJsonPath('data.mandatory_contributions.contribution_plan_totals.total_commitment', 2500)
            ->assertJsonPath('data.mandatory_contributions.contribution_plan_totals.total_outstanding', 2500);

        $plans = collect($response->json('data.mandatory_contributions.contribution_plans'));
        $this->assertCount(2, $plans);
        $this->assertTrue($plans->contains(fn (array $row) => $row['plan_code'] === 'MONTHLY_PARISH' && $row['assigned_amount'] == 500));
        $this->assertTrue($plans->contains(fn (array $row) => $row['plan_code'] === 'CUSTOM_FAMILY' && $row['assigned_amount'] == 2000));
        $this->assertSame('active', $plans->firstWhere('plan_code', 'MONTHLY_PARISH')['status']);
    }

    #[Test]
    public function it_collects_payment_against_existing_pledged_donation(): void
    {
        $entry = $this->postJson('/api/tenant/donations/entries', [
            'title' => 'Memorial Pledge',
            'pledged_amount' => 1000,
            'status' => 'pledged',
        ])->assertCreated()->json('data');

        $response = $this->postJson('/api/tenant/donations/entries/collect', [
            'donation_id' => $entry['id'],
            'amount' => 400,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.donation.status', 'partially_paid')
            ->assertJsonPath('data.donation.collected_amount', '400.00');
    }

    #[Test]
    public function it_includes_persona_and_saved_views_in_dashboard_summary(): void
    {
        $response = $this->getJson('/api/tenant/donations/dashboard/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'persona' => [
                        'persona',
                        'label',
                        'emphasis',
                        'sections',
                        'default_dashboard_view',
                        'quick_actions',
                    ],
                    'saved_views' => [
                        ['key', 'label', 'description'],
                    ],
                ],
            ])
            ->assertJsonPath('data.persona.default_dashboard_view', 'local')
            ->assertJsonPath('data.persona.persona', 'admin')
            ->assertJsonPath('data.persona.label', 'Administrator View')
            ->assertJsonPath(
                'data.persona.emphasis',
                'Collections, family follow-up, and parish operations in one place.'
            );

        $this->assertNotEmpty($response->json('data.saved_views'));
    }

    #[Test]
    public function it_returns_grouped_financial_search_results(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Searchable Family',
            'family_code' => 'SF-001',
            'status' => 'active',
        ]);

        $project = DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Searchable Building Fund',
            'code' => 'SBF-01',
            'target_amount' => 50000,
            'raised_amount' => 1000,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/tenant/donations/search?q=Searchable');

        $response->assertOk()
            ->assertJsonPath('data.query', 'Searchable')
            ->assertJsonStructure([
                'data' => [
                    'query',
                    'total',
                    'groups' => [
                        ['type', 'label', 'items'],
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
        $this->assertNotEmpty($response->json('data.groups'));
    }

    #[Test]
    public function it_applies_saved_view_presets(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Dues',
            'code' => 'MON',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-05',
            'due_date' => now()->subDays(10)->toDateString(),
            'amount_due' => 600,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $presets = $this->getJson('/api/tenant/donations/saved-views');
        $presets->assertOk()
            ->assertJsonStructure(['data' => [['key', 'label', 'description']]]);

        $response = $this->getJson('/api/tenant/donations/saved-views/outstanding_families');
        $response->assertOk()
            ->assertJsonPath('data.key', 'outstanding_families')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.family_id', $family->id);
    }

    #[Test]
    public function it_returns_receipt_print_html_for_payment(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Print Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 250,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $response = $this->get('/api/tenant/donations/payments/'.$payment['id'].'/receipt/print');
        $response->assertOk();
        $this->assertStringContainsString('Official Contribution Receipt', $response->getContent());
    }

    #[Test]
    public function it_lists_and_creates_campaigns(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Building Fund',
            'code' => 'BLDG',
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/tenant/donations/campaigns', [
            'fund_id' => $fund->id,
            'name' => 'Roof Repair Drive',
            'code' => 'ROOF-26',
            'campaign_type' => 'building',
            'target_amount' => 100000,
            'status' => 'active',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.entity_kind', 'campaign')
            ->assertJsonPath('data.campaign_type', 'building');

        $list = $this->getJson('/api/tenant/donations/campaigns');
        $list->assertOk()
            ->assertJsonPath('data.0.name', 'Roof Repair Drive');

        $projects = $this->getJson('/api/tenant/donations/projects');
        $projects->assertOk();
        $this->assertEmpty($projects->json('data'));
    }

    #[Test]
    public function project_index_summary_needs_attention_matches_resolved_funding_targets(): void
    {
        Family::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'On Track Hall',
            'code' => 'ONTRACK',
            'assignment_mode' => 'uniform',
            'default_family_target' => 5000,
            'target_amount' => 10000,
            'status' => 'active',
            'raised_amount' => 6000,
        ]);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Behind Chapel',
            'code' => 'BEHIND',
            'assignment_mode' => 'uniform',
            'default_family_target' => 5000,
            'target_amount' => 10000,
            'status' => 'active',
            'raised_amount' => 1000,
        ]);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Target Yet',
            'code' => 'NOTARGET',
            'assignment_mode' => 'uniform',
            'default_family_target' => 0,
            'target_amount' => 0,
            'status' => 'active',
            'raised_amount' => 0,
        ]);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Draft Wing',
            'code' => 'DRAFT',
            'assignment_mode' => 'uniform',
            'default_family_target' => 5000,
            'target_amount' => 10000,
            'status' => 'draft',
            'raised_amount' => 0,
        ]);

        $response = $this->getJson('/api/tenant/donations/projects')->assertOk();
        $rows = collect($response->json('data'))->keyBy('code');

        $this->assertCount(4, $rows);
        $this->assertFalse($rows['ONTRACK']['needs_attention']);
        $this->assertSame(60.0, (float) $rows['ONTRACK']['collection_percentage']);
        $this->assertTrue($rows['BEHIND']['needs_attention']);
        $this->assertSame(10.0, (float) $rows['BEHIND']['collection_percentage']);
        $this->assertFalse($rows['NOTARGET']['needs_attention']);
        $this->assertNull($rows['NOTARGET']['collection_percentage']);
        $this->assertFalse($rows['NOTARGET']['has_funding_target']);
        $this->assertFalse($rows['DRAFT']['needs_attention']);

        $active = $rows->filter(fn (array $row): bool => $row['status'] === 'active');
        $this->assertCount(3, $active);
        $this->assertSame(1, $active->filter(fn (array $row): bool => (bool) $row['needs_attention'])->count());
    }

    #[Test]
    public function campaign_index_returns_batch_list_summary_fields(): void
    {
        Family::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'entity_kind' => 'campaign',
            'name' => 'Roof Appeal',
            'code' => 'ROOF',
            'campaign_type' => 'building',
            'assignment_mode' => 'uniform',
            'default_family_target' => 0,
            'target_amount' => 50000,
            'status' => 'active',
            'raised_amount' => 12500,
        ]);

        DonationProject::create([
            'tenant_id' => $this->tenant->id,
            'entity_kind' => 'campaign',
            'name' => 'Charity Walk',
            'code' => 'WALK',
            'campaign_type' => 'charity',
            'assignment_mode' => 'uniform',
            'default_family_target' => 0,
            'target_amount' => 10000,
            'status' => 'active',
            'raised_amount' => 1000,
        ]);

        $response = $this->getJson('/api/tenant/donations/campaigns')->assertOk();
        $rows = collect($response->json('data'))->keyBy('code');

        $this->assertCount(2, $rows);
        $this->assertSame(25.0, (float) $rows['ROOF']['collection_percentage']);
        $this->assertSame(3, (int) $rows['ROOF']['families_enrolled']);
        $this->assertSame(10.0, (float) $rows['WALK']['collection_percentage']);
    }

    #[Test]
    public function it_keeps_semantic_engine_when_llm_is_disabled(): void
    {
        config(['financial_ai.llm.enabled' => false]);

        $response = $this->postJson('/api/tenant/donations/ai/ask', [
            'prompt' => 'Forecast collections for the next 3 months',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.engine', 'semantic_v2');
    }

    #[Test]
    public function it_returns_operations_dashboard_summary(): void
    {
        $response = $this->getJson('/api/tenant/donations/dashboard/operations');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'persona' => ['persona', 'label', 'emphasis', 'sections'],
                    'tenant_context' => ['currency_code'],
                    'period' => ['month_start', 'month_end', 'timezone'],
                    'collection_trend',
                    'collections_by_method_this_month',
                    'financial' => [
                        'totals' => ['collected', 'pending_dues', 'current_month_collected', 'annual_collected'],
                        'attention_summary' => ['count', 'total_overdue_amount'],
                    ],
                    'families_requiring_attention',
                    'recent_activity',
                    'saved_views',
                ],
            ]);
    }

    #[Test]
    public function it_bounds_current_month_collected_to_tenant_business_month(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $thisMonth = now()->startOfMonth()->toDateString();
        $lastMonth = now()->copy()->subMonth()->endOfMonth()->toDateString();
        $nextMonth = now()->copy()->addMonth()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-LAST-MONTH',
            'payer_name' => 'Last Month',
            'payment_date' => $lastMonth,
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-THIS-MONTH',
            'payer_name' => 'This Month',
            'payment_date' => $thisMonth,
            'amount' => 200,
            'currency' => 'INR',
            'method' => 'bank_transfer',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-NEXT-MONTH',
            'payer_name' => 'Next Month',
            'payment_date' => $nextMonth,
            'amount' => 300,
            'currency' => 'INR',
            'method' => 'cheque',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/operations');

        $response->assertOk()
            ->assertJsonPath('data.financial.totals.current_month_collected', 200)
            ->assertJsonPath('data.collections_by_method_this_month.bank_transfer', 200);

        $methodMix = $response->json('data.collections_by_method_this_month') ?? [];
        $this->assertArrayNotHasKey('cash', $methodMix);
        $this->assertArrayNotHasKey('cheque', $methodMix);

        $trend = $response->json('data.collection_trend') ?? [];
        $this->assertCount(6, $trend);
        $lastBucket = end($trend);
        $this->assertIsArray($lastBucket);
        $this->assertSame(now()->format('Y-m'), $lastBucket['period']);
        $this->assertSame(200.0, (float) $lastBucket['collected']);
        $this->assertSame(
            (float) $response->json('data.financial.totals.current_month_collected'),
            (float) $lastBucket['collected']
        );

        $previousBucket = $trend[count($trend) - 2] ?? null;
        $this->assertIsArray($previousBucket);
        $this->assertSame(now()->copy()->subMonth()->format('Y-m'), $previousBucket['period']);
        $this->assertSame(100.0, (float) $previousBucket['collected']);

        $trendTotal = array_sum(array_map(static fn ($row) => (float) ($row['collected'] ?? 0), $trend));
        $this->assertSame(300.0, $trendTotal);
    }

    #[Test]
    public function it_returns_financial_activity_timeline_for_family_and_payment(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $payment = $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Timeline Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 300,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $familyTimeline = $this->getJson('/api/tenant/donations/activity/timeline?subject_type=family&subject_id='.$family->id);
        $familyTimeline->assertOk()
            ->assertJsonPath('data.subject_type', 'family')
            ->assertJsonPath('data.subject_id', (string) $family->id)
            ->assertJsonStructure(['data' => ['count', 'events']]);

        $paymentTimeline = $this->getJson('/api/tenant/donations/activity/timeline?subject_type=payment&subject_id='.$payment['id']);
        $paymentTimeline->assertOk()
            ->assertJsonPath('data.subject_type', 'payment')
            ->assertJsonPath('data.subject_id', (string) $payment['id'])
            ->assertJsonPath('data.events.0.type', 'payment');

        $this->assertGreaterThanOrEqual(1, $paymentTimeline->json('data.count'));
    }

    #[Test]
    public function it_returns_executive_report_narrative(): void
    {
        $response = $this->getJson('/api/tenant/donations/reports/executive-summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'narrative',
                    'highlights',
                    'recommended_actions',
                    'metrics' => [
                        'health_score',
                        'health_status',
                        'current_month_collected',
                        'pending_dues',
                        'overdue_family_count',
                        'overdue_amount',
                        'previous_month_collected',
                    ],
                    'visuals' => [
                        'health' => ['score', 'label', 'status', 'factors'],
                        'collections',
                        'outstanding',
                        'participation',
                        'collection_trend',
                        'forecast' => ['history', 'current_month_collected', 'current_month_projection'],
                    ],
                ],
            ])
            ->assertJsonPath('data.title', 'Executive Stewardship Summary');

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary');
        $dashboard->assertOk();
        $response->assertJsonPath(
            'data.metrics.current_month_collected',
            $dashboard->json('data.period_collections.current_month_collected')
        );
        $response->assertJsonPath(
            'data.metrics.pending_dues',
            $dashboard->json('data.totals.pending_dues')
        );
    }

    #[Test]
    public function it_returns_outstanding_overdue_drill_down_reconciled_with_executive_summary(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'DRILL-FUND',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Plan',
            'code' => 'DRILL-PLAN',
            'frequency' => 'monthly',
            'default_amount' => 300,
            'status' => 'active',
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_name' => 'Drill Down Family',
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-03',
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'due_date' => now()->subDays(15)->toDateString(),
            'amount_due' => 300,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'outstanding_overdue',
            'data_element_id' => 'overdue_amount',
            'slice_id' => 'overdue',
            'per_page' => 20,
        ]));

        $drill->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'context' => ['expected_amount', 'expected_count', 'business_date', 'why_this_number'],
                    'summary' => ['family_count', 'due_count', 'amount_total'],
                    'data' => ['data', 'total'],
                ],
            ]);

        $drill->assertJsonPath(
            'data.context.expected_amount',
            $executive->json('data.visuals.outstanding.overdue_amount')
        );
        $drill->assertJsonPath(
            'data.context.expected_count',
            $executive->json('data.visuals.outstanding.overdue_family_count')
        );
        $this->assertGreaterThanOrEqual(1, $drill->json('data.data.total'));
    }

    #[Test]
    public function it_returns_collections_drill_down_reconciled_with_executive_summary(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $thisMonth = now()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-COLL-DRILL',
            'payer_name' => 'Collections Drill',
            'payment_date' => $thisMonth,
            'amount' => 250,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();
        $this->assertGreaterThanOrEqual(
            250,
            (float) $executive->json('data.visuals.collections.current_month_collected')
        );

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
            'per_page' => 20,
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.record_kind', 'payment')
            ->assertJsonPath('data.context.value_kind', 'money')
            ->assertJsonPath('data.context.point_kind', 'actual');

        $drill->assertJsonPath(
            'data.context.expected_amount',
            $executive->json('data.visuals.collections.current_month_collected')
        );
        $this->assertGreaterThanOrEqual(1, (int) $drill->json('data.data.total'));
    }

    #[Test]
    public function it_returns_forecast_projection_drill_down_without_payment_rows(): void
    {
        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'month_end_forecast',
            'data_element_id' => 'current_month_projection',
            'slice_id' => 'current_month_projection',
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.point_kind', 'forecast')
            ->assertJsonPath('data.context.record_kind', 'none')
            ->assertJsonPath('data.data.total', 0)
            ->assertJsonPath('data.data.data', []);

        $drill->assertJsonPath(
            'data.context.expected_amount',
            $executive->json('data.visuals.forecast.current_month_projection')
        );
    }

    #[Test]
    public function it_rejects_overdue_and_remaining_only_on_dues_index(): void
    {
        $response = $this->getJson('/api/tenant/donations/dues?overdue_only=1&remaining_only=1');
        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_invalid_due_schedule_on_dues_index(): void
    {
        $this->getJson('/api/tenant/donations/dues?due_schedule=invalid')->assertStatus(422);
    }

    #[Test]
    public function it_rejects_due_schedule_combined_with_overdue_only_on_dues_index(): void
    {
        $this->getJson('/api/tenant/donations/dues?due_schedule=overdue&overdue_only=1')->assertStatus(422);
    }

    #[Test]
    public function it_rejects_invalid_sort_on_dues_index(): void
    {
        $this->getJson('/api/tenant/donations/dues?actionable=1&sort=not_a_column')->assertStatus(422);
    }

    #[Test]
    public function it_accepts_frontend_boolean_query_strings_on_dues_index(): void
    {
        $this->getJson(
            '/api/tenant/donations/dues?page=1&per_page=20&actionable=true&sort=due_date&direction=asc'
        )->assertOk();
    }

    #[Test]
    public function it_sorts_dues_index_by_due_date_with_actionable_true_string(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F-DATE-SORT',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON-DATE-SORT',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-01',
            'period_start' => now()->subDays(30)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-02',
            'period_start' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $sorted = $this->getJson(
            '/api/tenant/donations/dues?actionable=true&sort=due_date&direction=asc&per_page=50'
        )->assertOk();

        $dates = collect($sorted->json('data.data'))->pluck('due_date')->all();
        $this->assertSame($dates, collect($dates)->sort()->values()->all());
    }

    #[Test]
    public function it_sorts_dues_index_by_family_name(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F-SORT',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON-SORT',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $familyZ = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Zebra Parish Family',
        ]);
        $familyA = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Alpha Parish Family',
        ]);

        $dueDate = now()->subDays(5)->toDateString();
        $periodStart = now()->subDays(10)->toDateString();

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $familyZ->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-03',
            'period_start' => $periodStart,
            'due_date' => $dueDate,
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $familyA->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-03',
            'period_start' => $periodStart,
            'due_date' => $dueDate,
            'amount_due' => 50,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $sorted = $this->getJson('/api/tenant/donations/dues?actionable=1&sort=family_name&direction=asc&per_page=50')
            ->assertOk();

        $names = collect($sorted->json('data.data'))->pluck('family.family_name')->all();
        $this->assertSame(['Alpha Parish Family', 'Zebra Parish Family'], array_slice($names, 0, 2));
    }

    #[Test]
    public function overdue_dues_index_meta_family_count_matches_dashboard_attention_summary(): void
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fund',
            'code' => 'F-OVD-META',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON-OVD',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $familyA = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $familyB = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $overdueDate = now()->subDays(10)->toDateString();

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $familyA->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-01',
            'due_date' => $overdueDate,
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $familyA->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-02',
            'due_date' => $overdueDate,
            'amount_due' => 50,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);
        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $familyB->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-01',
            'due_date' => $overdueDate,
            'amount_due' => 75,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $attentionCount = (int) $dashboard->json('data.attention_summary.count');

        $dues = $this->getJson('/api/tenant/donations/dues?overdue_only=1')->assertOk();
        $dues->assertJsonPath('meta.overdue_family_count', $attentionCount);
        $this->assertGreaterThanOrEqual(2, (int) $dues->json('meta.overdue_family_count'));
    }

    #[Test]
    public function it_delivers_queued_whatsapp_outreach_in_manual_mode(): void
    {
        config(['whatsapp_business.enabled' => false]);

        $family = $this->createWhatsAppEligibleFamily();

        $queue = $this->postJson('/api/tenant/donations/outreach/whatsapp/queue', [
            'family_ids' => [$family->id],
        ]);

        $queue->assertCreated()
            ->assertJsonPath('data.queued_count', 1);

        $deliver = $this->postJson('/api/tenant/donations/outreach/whatsapp/deliver-pending');
        $deliver->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.delivered', 1)
            ->assertJsonPath('data.results.0.status', 'sent')
            ->assertJsonPath('data.results.0.delivery_mode', 'manual_link');

        $summary = $this->getJson('/api/tenant/donations/outreach/whatsapp/delivery-summary');
        $summary->assertOk()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.business_api_enabled', false);
    }

    #[Test]
    public function it_returns_whatsapp_delivery_summary(): void
    {
        $response = $this->getJson('/api/tenant/donations/outreach/whatsapp/delivery-summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'queued',
                    'sent',
                    'failed',
                    'business_api_enabled',
                    'recent',
                ],
            ]);
    }

    #[Test]
    public function it_returns_financial_ai_status(): void
    {
        config(['financial_ai.llm.enabled' => false]);

        $response = $this->getJson('/api/tenant/donations/ai/status');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'global_llm_enabled',
                    'tenant_llm_enabled',
                    'llm_active',
                    'default_engine',
                    'whatsapp_business_enabled',
                ],
            ])
            ->assertJsonPath('data.global_llm_enabled', false)
            ->assertJsonPath('data.llm_active', false);
    }

    #[Test]
    public function it_returns_stewardship_print_html(): void
    {
        $response = $this->get('/api/tenant/donations/reports/stewardship/print');
        $response->assertOk();
        $this->assertStringContainsString('Executive Stewardship Summary', $response->getContent());
        $this->assertStringContainsString('Recommended Actions', $response->getContent());
    }

    #[Test]
    public function it_returns_executive_board_pack_print_html(): void
    {
        $response = $this->get('/api/tenant/donations/reports/executive-board/print');
        $response->assertOk();
        $this->assertStringContainsString('Executive Stewardship Summary', $response->getContent());
        $this->assertStringContainsString('Recommended Actions', $response->getContent());
        $this->assertStringContainsString('page-break-after', $response->getContent());
    }

    #[Test]
    public function it_returns_parish_comparison_report_for_diocese(): void
    {
        $this->tenant->update([
            'tenant_tier' => 'diocese',
            'name' => 'Comparison Diocese',
        ]);
        $this->tenant->refresh();

        Tenant::factory()->create([
            'parent_tenant_id' => $this->tenant->id,
            'tenant_tier' => 'parish',
            'name' => 'Comparison Parish',
            'features' => ['donations'],
        ]);

        $response = $this->getJson('/api/tenant/donations/reports/parish-comparison');
        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.title', 'Parish Comparison Report')
            ->assertJsonStructure([
                'data' => [
                    'narrative',
                    'highlights',
                    'parishes' => [
                        ['name', 'health_score', 'total_collected', 'pending_dues'],
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_persists_llm_toggle_in_donation_settings_metadata(): void
    {
        $update = $this->putJson('/api/tenant/donations/settings', [
            'financial_year_start_month' => '04',
            'financial_year_start_day' => '01',
            'receipt_prefix' => 'RCPT',
            'metadata' => [
                'financial_ai_llm_enabled' => false,
            ],
        ]);

        $update->assertOk();

        $show = $this->getJson('/api/tenant/donations/settings');
        $show->assertOk()
            ->assertJsonPath('data.metadata.financial_ai_llm_enabled', false);
    }

    #[Test]
    public function it_returns_family_statement_print_html(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->get("/api/tenant/donations/families/{$family->id}/financial-profile/print");
        $response->assertOk();
        $this->assertStringContainsString('Family Financial Statement', $response->getContent());
        $this->assertStringContainsString('Financial Health', $response->getContent());
    }

    #[Test]
    public function it_lists_donation_notifications(): void
    {
        $family = $this->createWhatsAppEligibleFamily();

        $this->postJson('/api/tenant/donations/outreach/whatsapp/queue', [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $response = $this->getJson('/api/tenant/donations/notifications');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        ['id', 'notification_type', 'channel', 'status'],
                    ],
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, count($response->json('data.data')));
    }

    #[Test]
    public function it_exposes_branch_visibility_scope_on_dashboard(): void
    {
        $this->tenant->update([
            'tenant_tier' => 'branch',
            'name' => 'Branch Chapel',
        ]);
        $this->tenant->refresh();

        $response = $this->getJson('/api/tenant/donations/dashboard/summary');
        $response->assertOk()
            ->assertJsonPath('data.tenant_context.visibility_scope', 'branch_only')
            ->assertJsonPath('data.tenant_context.supports_child_rollup', false);
    }

    #[Test]
    public function it_includes_enriched_dashboard_summary_fields(): void
    {
        $response = $this->getJson('/api/tenant/donations/dashboard/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'health_scores' => [
                        'financial',
                        'collection_performance',
                        'family_engagement',
                        'project_funding',
                    ],
                    'kpis' => [
                        'average_contribution',
                        'collection_growth_pct',
                        'plan_compliance_pct',
                        'contributing_families_delta',
                        'previous_month_collected',
                    ],
                    'collection_performance_chart' => [
                        'granularity',
                        'series',
                    ],
                    'proactive_insights',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.collection_performance_chart.series'));

        $series = collect($response->json('data.collection_performance_chart.series'))->keyBy('key');
        $collected = $series->get('collected')['points'] ?? [];
        $outstanding = $series->get('outstanding')['points'] ?? [];
        $target = $series->get('target')['points'] ?? [];
        $this->assertCount(count($collected), $outstanding);
        $this->assertCount(count($collected), $target);

        foreach ($collected as $index => $point) {
            $expectedOutstanding = max(0, round(($target[$index]['value'] ?? 0) - ($point['value'] ?? 0), 2));
            $this->assertEquals($expectedOutstanding, (float) ($outstanding[$index]['value'] ?? 0));
        }
    }

    #[Test]
    public function it_delivers_whatsapp_via_business_api_when_enabled(): void
    {
        config([
            'whatsapp_business.enabled' => true,
            'whatsapp_business.phone_number_id' => '123456789',
            'whatsapp_business.access_token' => 'test-token',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200),
        ]);

        $family = $this->createWhatsAppEligibleFamily();

        $this->postJson('/api/tenant/donations/outreach/whatsapp/queue', [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $deliver = $this->postJson('/api/tenant/donations/outreach/whatsapp/deliver-pending');
        $deliver->assertOk()
            ->assertJsonPath('data.delivered', 1);

        $modes = collect($deliver->json('data.results'))->pluck('delivery_mode')->all();
        $this->assertContains('business_api', $modes);
    }

    private function createWhatsAppEligibleFamily(): Family
    {
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'WhatsApp Fund',
            'code' => 'WA-FUND',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'WhatsApp Plan',
            'code' => 'WA-PLAN',
            'frequency' => 'monthly',
            'default_amount' => 500,
            'status' => 'active',
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'head_of_family' => 'WhatsApp Head',
        ]);

        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'WhatsApp',
            'last_name' => 'Head',
            'relationship_to_head' => 'self',
            'phone' => '9876543210',
            'is_primary_contact' => true,
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-05',
            'due_date' => now()->subDays(10)->toDateString(),
            'amount_due' => 500,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        return $family;
    }

    #[Test]
    public function it_returns_financial_command_center_payload(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Command Center Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 750,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson('/api/tenant/donations/dashboard/command-center');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'meta' => ['church_name', 'financial_year', 'last_synced_at'],
                    'health_index' => ['score', 'label', 'status', 'ai_summary'],
                    'collection_health' => ['score', 'status_label', 'factors', 'issues', 'recommended_actions', 'insights'],
                    'executive_cards',
                    'action_center',
                    'analytics' => ['collection_trend', 'family_engagement', 'geographic'],
                    'intelligence',
                    'ai_advisor',
                    'collections_command',
                    'projects_command',
                    'communication_center',
                    'expense_summary',
                    'persona',
                ],
            ])
            ->assertJsonPath('data.collections_command.today_count', 1);
    }

    #[Test]
    public function it_records_parish_expense_disbursements(): void
    {
        $response = $this->postJson('/api/tenant/donations/expenses', [
            'category' => 'Utilities',
            'amount' => 1200,
            'expense_date' => now()->toDateString(),
            'payee' => 'Electric Board',
            'method' => 'bank_transfer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'Utilities')
            ->assertJsonPath('data.amount', '1200.00');

        $this->assertDatabaseHas('parish_expenses', [
            'tenant_id' => $this->tenant->id,
            'category' => 'Utilities',
        ]);
    }

    #[Test]
    public function it_returns_action_center_queues_with_expected_structure(): void
    {
        $family = $this->createWhatsAppEligibleFamily();

        $response = $this->getJson('/api/tenant/donations/dashboard/command-center');

        $response->assertOk();
        $queues = $response->json('data.action_center');
        $this->assertCount(6, $queues);
        $this->assertArrayHasKey('key', $queues[0]);
        $this->assertArrayHasKey('priority', $queues[0]);
        $this->assertArrayHasKey('cta_route', $queues[0]);

        $followUp = collect($queues)->firstWhere('key', 'families_follow_up');
        $this->assertNotNull($followUp);
        $this->assertGreaterThanOrEqual(1, $followUp['affected_count']);
    }

    #[Test]
    public function it_returns_geographic_breakdown_for_family_payments(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'city' => 'Kochi',
        ]);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Geo Payer',
            'payment_date' => now()->toDateString(),
            'amount' => 900,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson('/api/tenant/donations/dashboard/command-center');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'analytics' => [
                        'geographic' => ['by_bcc', 'by_city'],
                    ],
                    'contribution_intelligence' => [
                        'top_contributors',
                        'largest_gifts',
                        'returning_families',
                        'giving_streaks',
                    ],
                    'ai_advisor' => ['narratives'],
                ],
            ]);

        $byCity = $response->json('data.analytics.geographic.by_city');
        $this->assertNotEmpty($byCity);
        $this->assertSame('Kochi', $byCity[0]['area_name']);
    }

    #[Test]
    public function it_filters_command_center_analytics_by_period(): void
    {
        $response = $this->getJson('/api/tenant/donations/dashboard/command-center?period=month');
        $response->assertOk();
        $monthTrend = $response->json('data.analytics.collection_trend');
        $this->assertCount(1, $monthTrend);

        $yearResponse = $this->getJson('/api/tenant/donations/dashboard/command-center?period=year');
        $yearResponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($yearResponse->json('data.analytics.collection_trend')));
    }

    #[Test]
    public function it_counts_only_collectable_period_dues_in_outstanding_total(): void
    {
        Passport::actingAs($this->tenantAdminUser);

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parish Fund',
            'code' => 'PARISH-OUT',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Family Contribution',
            'code' => 'MONTHLY-OUT',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $currentStart = now()->startOfMonth();
        $currentEnd = now()->copy()->endOfMonth();
        $nextStart = $currentStart->copy()->addMonth()->startOfMonth();
        $nextEnd = $nextStart->copy()->endOfMonth();

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => $currentStart->format('Y-m'),
            'period_start' => $currentStart->toDateString(),
            'period_end' => $currentEnd->toDateString(),
            'due_date' => $currentEnd->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => $nextStart->format('Y-m'),
            'period_start' => $nextStart->toDateString(),
            'period_end' => $nextEnd->toDateString(),
            'due_date' => $nextEnd->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");

        $response->assertOk()
            ->assertJsonPath('data.outstanding_balances.total', 100)
            ->assertJsonPath('data.totals.pending_due', 100)
            ->assertJsonPath('data.mandatory_contributions.totals.pending', 100);

        $this->assertCount(1, $response->json('data.mandatory_contributions.current_period_dues'));
        $this->assertCount(1, $response->json('data.mandatory_contributions.scheduled_dues'));
    }

    #[Test]
    public function it_classifies_fund_allocations_as_voluntary_paid_without_reducing_outstanding(): void
    {
        Passport::actingAs($this->tenantAdminUser);

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN-FUND',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON-FUND',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->endOfMonth()->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Test Family',
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'allocations' => [
                ['allocatable_type' => 'fund', 'allocatable_id' => $fund->id, 'amount' => 100],
            ],
        ])->assertCreated();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");

        $response->assertOk()
            ->assertJsonPath('data.totals.total_paid', 100)
            ->assertJsonPath('data.totals.voluntary_paid', 100)
            ->assertJsonPath('data.totals.mandatory_paid', 0)
            ->assertJsonPath('data.outstanding_balances.total', 100)
            ->assertJsonPath('data.mandatory_contributions.totals.paid', 0);

        $this->assertSame('pending', $due->fresh()->status);
    }

    #[Test]
    public function it_clears_outstanding_when_payment_is_allocated_to_due(): void
    {
        Passport::actingAs($this->tenantAdminUser);

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parish Fund',
            'code' => 'PARISH-DUE',
            'status' => 'active',
        ]);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON-DUE',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->endOfMonth()->toDateString(),
            'amount_due' => 100,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Test Family',
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'allocations' => [
                ['allocatable_type' => 'due', 'allocatable_id' => $due->id, 'amount' => 100],
            ],
        ])->assertCreated();

        $response = $this->getJson("/api/tenant/donations/families/{$family->id}/financial-profile");

        $response->assertOk()
            ->assertJsonPath('data.totals.total_paid', 100)
            ->assertJsonPath('data.totals.mandatory_paid', 100)
            ->assertJsonPath('data.outstanding_balances.total', 0)
            ->assertJsonPath('data.totals.pending_due', 0)
            ->assertJsonPath('data.mandatory_contributions.totals.paid', 100);

        $this->assertSame('paid', $due->fresh()->status);
        $this->assertSame('100.00', (string) $due->fresh()->amount_paid);
    }

    #[Test]
    public function it_generates_full_schedule_catch_up_through_current_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00:00', 'Asia/Kolkata'));

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Monthly Fund',
            'code' => 'MONTHLY_FUND',
            'status' => 'active',
        ]);

        $this->createFamiliesEligibleForCatchUpSchedule(2);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly Family Contribution',
            'code' => 'MONTHLY_FAMILY',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'auto_generate' => true,
            'status' => 'active',
        ]);

        $preview = $this->getJson("/api/tenant/donations/plans/{$plan->id}/generation-preview");
        $preview->assertOk()->assertJsonPath('data.period_count', 9);

        $response = $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
            'generate_full_schedule' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.generated_count', 18)
            ->assertJsonPath('data.period_count', 9);

        $this->assertSame(18, ContributionDue::forTenant($this->tenant->id)->where('plan_id', $plan->id)->count());

        $repeat = $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
            'generate_full_schedule' => true,
        ]);

        $repeat->assertOk()
            ->assertJsonPath('data.generated_count', 0)
            ->assertJsonPath('data.unchanged_count', 18);

        $this->assertSame(18, ContributionDue::forTenant($this->tenant->id)->where('plan_id', $plan->id)->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function scheduled_generation_catch_up_creates_all_eligible_periods(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00:00', 'Asia/Kolkata'));

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Scheduled Fund',
            'code' => 'SCHED_FUND',
            'status' => 'active',
        ]);

        $this->createFamiliesEligibleForCatchUpSchedule(1);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Auto Monthly',
            'code' => 'AUTO_MONTHLY',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'auto_generate' => true,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/tenant/donations/contributions/generate-scheduled');

        $response->assertOk()->assertJsonPath('data.generated', 9);
        $this->assertSame(9, ContributionDue::forTenant($this->tenant->id)->where('plan_id', $plan->id)->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function full_schedule_generation_preserves_paid_installment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00:00', 'Asia/Kolkata'));

        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preserve Fund',
            'code' => 'PRESERVE_FUND',
            'status' => 'active',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Preserve Paid',
            'code' => 'PRESERVE_PAID',
            'plan_type' => 'uniform',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        $januaryDue = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-01',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'due_date' => '2026-01-31',
            'amount_due' => 100,
            'amount_paid' => 100,
            'status' => 'paid',
        ]);

        $this->postJson("/api/tenant/donations/plans/{$plan->id}/generate-dues", [
            'generate_full_schedule' => true,
        ])->assertOk();

        $januaryDue->refresh();
        $this->assertSame('paid', $januaryDue->status);
        $this->assertSame('100.00', (string) $januaryDue->amount_paid);

        Carbon::setTestNow();
    }

    /**
     * Uniform plans only issue catch-up dues for periods on or after the family joined.
     */
    private function createFamiliesEligibleForCatchUpSchedule(int $count): void
    {
        Family::factory()->count($count)->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'created_at' => Carbon::parse('2026-01-01 08:00:00', 'Asia/Kolkata'),
            'updated_at' => Carbon::parse('2026-01-01 08:00:00', 'Asia/Kolkata'),
        ]);
    }

    private function seedOfficialAddressForTenant(Tenant $tenant, Country $country): void
    {
        Address::query()->create([
            'addressable_id' => $tenant->id,
            'addressable_type' => Tenant::class,
            'address_type' => 'official',
            'line1' => '1 Parish Way',
            'district' => 'City',
            'state_province' => 'Region',
            'country' => $country->name,
            'country_id' => $country->id,
            'pin_zip_code' => '00000',
            'active' => 1,
            'is_default' => true,
        ]);
    }
}
