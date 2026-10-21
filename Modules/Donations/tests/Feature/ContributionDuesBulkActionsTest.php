<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Modules\Donations\Jobs\SendContributionReminderJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class ContributionDuesBulkActionsTest extends DonationsCertificationTestCase
{
    #[Test]
    public function bulk_waive_processes_eligible_dues_and_skips_ineligible_or_foreign_ids(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $pending = $this->seedDue($ctx['tenant']->id);
        $paidSeed = $this->seedDue($ctx['tenant']->id);
        $paidSeed['due']->update(['status' => 'paid', 'amount_paid' => $paidSeed['due']->amount_due]);

        $foreign = $this->makeTenantUser(['donations.view', 'donations.manage']);
        $foreignDue = $this->seedDue($foreign['tenant']->id);

        $unknownId = '11111111-1111-4111-8111-111111111111';

        $response = $this->postJson('/api/tenant/donations/dues/bulk-waive', [
            'due_ids' => [
                $pending['due']->id,
                $pending['due']->id,
                $paidSeed['due']->id,
                $foreignDue['due']->id,
                $unknownId,
            ],
            'reason' => 'Pastoral hardship',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.skipped_count', 3)
            ->assertJsonPath('data.processed_ids.0', $pending['due']->id);

        $this->assertSame('waived', $pending['due']->fresh()->status);
        $this->assertSame('paid', $paidSeed['due']->fresh()->status);
        $this->assertSame('pending', $foreignDue['due']->fresh()->status);
        $this->assertStringContainsString('could not be processed', $response->json('message'));
    }

    #[Test]
    public function bulk_waive_is_idempotent_for_already_waived_dues(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $seed = $this->seedDue($ctx['tenant']->id);
        $this->postJson('/api/tenant/donations/dues/bulk-waive', [
            'due_ids' => [$seed['due']->id],
        ])->assertOk()->assertJsonPath('data.processed_count', 1);

        $this->postJson('/api/tenant/donations/dues/bulk-waive', [
            'due_ids' => [$seed['due']->id],
        ])->assertOk()
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.skipped.0.reason', 'This due is already waived.');
    }

    #[Test]
    public function bulk_waive_requires_manage_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $seed = $this->seedDue($ctx['tenant']->id);

        $this->postJson('/api/tenant/donations/dues/bulk-waive', [
            'due_ids' => [$seed['due']->id],
        ])->assertForbidden();

        $this->assertSame('pending', $seed['due']->fresh()->status);
    }

    #[Test]
    public function bulk_remind_queues_jobs_only_for_tenant_dues(): void
    {
        Queue::fake();

        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.notifications']);
        $first = $this->seedDue($ctx['tenant']->id);
        $second = $this->seedDue($ctx['tenant']->id, Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
        ]));
        $overdueOn = now()->subDay()->toDateString();
        $first['due']->update(['due_date' => $overdueOn]);
        $second['due']->update(['due_date' => $overdueOn]);
        $foreign = $this->makeTenantUser(['donations.view']);
        $foreignDue = $this->seedDue($foreign['tenant']->id);

        $this->postJson('/api/tenant/donations/dues/bulk-remind', [
            'due_ids' => [$first['due']->id, $second['due']->id, $foreignDue['due']->id],
        ])->assertOk()
            ->assertJsonPath('data.processed_count', 2)
            ->assertJsonPath('data.skipped_count', 1);

        Queue::assertPushed(SendContributionReminderJob::class, 2);
        $this->assertSame(2, ContributionDue::forTenant($ctx['tenant']->id)->count());
    }

    #[Test]
    public function bulk_remind_requires_notifications_permission(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $seed = $this->seedDue($ctx['tenant']->id);

        $this->postJson('/api/tenant/donations/dues/bulk-remind', [
            'due_ids' => [$seed['due']->id],
        ])->assertForbidden();
    }
}
