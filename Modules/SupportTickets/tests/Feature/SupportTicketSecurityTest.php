<?php

namespace Modules\SupportTickets\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportTickets\Database\Seeders\SupportTicketsDatabaseSeeder;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Models\SupportTicketComment;
use Modules\SupportTickets\Support\ResolvedByActor;
use Modules\SupportTickets\Support\TenantResolutionCategory;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportTicketSecurityTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupportTicketsDatabaseSeeder::class);

        $this->tenantA = $this->createWritableTenant();
        $this->tenantB = $this->createWritableTenant();

        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'active' => 1,
        ]);
        $this->userB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'active' => 1,
        ]);

        $this->grantPermissions($this->userA, [
            'support.tickets.view',
            'support.tickets.create',
            'support.tickets.comment',
            'support.tickets.view_all_tenant',
            'support.tickets.cancel',
            'support.tickets.resolve',
            'support.tickets.reopen',
        ]);
        $this->grantPermissions($this->userB, [
            'support.tickets.view',
            'support.tickets.create',
        ]);
    }

    #[Test]
    public function tenant_user_can_view_own_ticket(): void
    {
        Passport::actingAs($this->userA);

        $created = $this->postJson('/api/tenant/support/tickets', [
            'request_type_id' => $this->technicalRequestTypeId(),
            'subject' => 'Cannot log in',
            'description' => 'Password reset fails',
            'submit' => true,
        ])->assertCreated();

        $this->getJson('/api/tenant/support/tickets/'.$created->json('data.ticket_number'))
            ->assertOk()
            ->assertJsonPath('data.subject', 'Cannot log in');
    }

    #[Test]
    public function tenant_b_cannot_access_tenant_a_ticket(): void
    {
        Passport::actingAs($this->userA);
        $ticketNumber = $this->postJson('/api/tenant/support/tickets', [
            'request_type_id' => $this->technicalRequestTypeId(),
            'subject' => 'Private issue',
            'description' => 'Details',
            'submit' => true,
        ])->json('data.ticket_number');

        Passport::actingAs($this->userB);
        $this->getJson('/api/tenant/support/tickets/'.$ticketNumber)->assertNotFound();
    }

    #[Test]
    public function scope_all_requires_view_all_permission(): void
    {
        $priest = User::factory()->create(['tenant_id' => $this->tenantA->id, 'active' => 1]);
        $this->grantPermissions($priest, ['support.tickets.view', 'support.tickets.create']);

        Passport::actingAs($priest);
        $this->getJson('/api/tenant/support/tickets?scope=all')->assertForbidden();
    }

    #[Test]
    public function tenant_resource_excludes_internal_notes(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
        ]);

        SupportTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $this->tenantA->id,
            'author_user_id' => $this->userA->id,
            'is_internal' => true,
            'body' => 'Secret staffing note',
        ]);

        SupportTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'tenant_id' => $this->tenantA->id,
            'author_user_id' => $this->userA->id,
            'is_internal' => false,
            'body' => 'Public reply',
        ]);

        Passport::actingAs($this->userA);
        $response = $this->getJson('/api/tenant/support/tickets/'.$ticket->ticket_number);

        $response->assertOk();
        $json = $response->json();
        $this->assertStringNotContainsString('Secret staffing note', json_encode($json));
        $this->assertStringContainsString('Public reply', json_encode($json));
    }

    #[Test]
    public function missing_create_permission_blocks_ticket_creation(): void
    {
        $viewer = User::factory()->create(['tenant_id' => $this->tenantA->id, 'active' => 1]);
        $this->grantPermissions($viewer, ['support.tickets.view']);

        Passport::actingAs($viewer);
        $this->postJson('/api/tenant/support/tickets', [
            'request_type_id' => $this->technicalRequestTypeId(),
            'subject' => 'Blocked',
            'description' => 'Should fail',
        ])->assertForbidden();
    }

    #[Test]
    public function invalid_status_transition_is_rejected(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::NEW,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/cancel', [
            'reason' => 'No longer needed',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::CANCELLED);

        $bad = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::RESOLVED,
        ]);

        $this->postJson('/api/tenant/support/tickets/'.$bad->ticket_number.'/cancel', [
            'reason' => 'Should fail',
        ])->assertUnprocessable();
    }

    #[Test]
    public function tenant_can_resolve_new_ticket_without_summary(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::NEW,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/resolve', [])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::CLOSED)
            ->assertJsonPath('data.resolution_category', TenantResolutionCategory::SELF_RESOLVED)
            ->assertJsonPath('data.resolved_by_actor', ResolvedByActor::TENANT)
            ->assertJsonPath('data.resolved_by_user_id', $this->userA->id);
    }

    #[Test]
    public function tenant_cannot_resolve_in_progress_without_summary(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/resolve', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resolution_summary']);
    }

    #[Test]
    public function tenant_can_resolve_in_progress_with_summary(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::IN_PROGRESS,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/resolve', [
            'resolution_summary' => 'We applied the workaround locally',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::CLOSED)
            ->assertJsonPath('data.resolution_category', TenantResolutionCategory::EXPLICIT_RESOLUTION)
            ->assertJsonPath('data.resolution_summary', 'We applied the workaround locally');
    }

    #[Test]
    public function tenant_resolve_from_awaiting_you_records_action_completed(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::AWAITING_YOU,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/resolve', [])
            ->assertOk()
            ->assertJsonPath('data.resolution_category', TenantResolutionCategory::ACTION_COMPLETED);
    }

    #[Test]
    public function tenant_cannot_resolve_cancelled_ticket(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::CANCELLED,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/resolve', [])
            ->assertUnprocessable();
    }

    #[Test]
    public function tenant_can_confirm_resolution_from_resolved(): void
    {
        $opsUser = $this->makeSupportOpsUser(['support.ops.tickets.view', 'support.ops.tickets.resolve']);

        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::IN_PROGRESS,
            'resolved_by_user_id' => null,
        ]);

        Passport::actingAs($opsUser);
        $this->postJson('/api/support/tickets/'.$ticket->ticket_number.'/resolve', [
            'resolution_summary' => 'Patched on the server',
        ])
            ->assertOk()
            ->assertJsonPath('data.resolved_by_actor', ResolvedByActor::EKKLESIA);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/confirm-resolution')
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::CLOSED);
    }

    #[Test]
    public function tenant_cannot_confirm_self_resolved_ticket(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::CLOSED,
            'resolved_by_actor' => ResolvedByActor::TENANT,
            'resolved_by_user_id' => $this->userA->id,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/confirm-resolution')
            ->assertForbidden();
    }

    #[Test]
    public function tenant_cannot_confirm_resolution_from_new(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::NEW,
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/confirm-resolution')
            ->assertForbidden();
    }

    #[Test]
    public function tenant_can_reopen_resolved_ticket_within_window(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::RESOLVED,
            'reopen_allowed_until' => now()->addDays(10),
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/reopen', [
            'reason' => 'Issue is not fixed',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::IN_PROGRESS);
    }

    #[Test]
    public function ops_assign_moves_new_ticket_to_in_progress(): void
    {
        $opsUser = $this->makeSupportOpsUser([
            'support.ops.tickets.view',
            'support.ops.tickets.assign',
            'support.ops.tickets.change_status',
        ]);

        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::NEW,
        ]);

        Passport::actingAs($opsUser);
        $this->postJson('/api/support/tickets/'.$ticket->ticket_number.'/assign', [])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::IN_PROGRESS)
            ->assertJsonPath('data.assigned_agent.id', $opsUser->id);
    }

    #[Test]
    public function tenant_cannot_reopen_without_reason(): void
    {
        $ticket = SupportTicket::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'requester_user_id' => $this->userA->id,
            'request_type_id' => $this->technicalRequestTypeId(),
            'status' => TicketStatus::RESOLVED,
            'reopen_allowed_until' => now()->addDays(10),
        ]);

        Passport::actingAs($this->userA);
        $this->postJson('/api/tenant/support/tickets/'.$ticket->ticket_number.'/reopen', [])
            ->assertUnprocessable();
    }

    private function createWritableTenant(): Tenant
    {
        return Tenant::factory()->create([
            'active' => 1,
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function makeSupportOpsUser(array $permissionNames): User
    {
        $role = Role::query()->firstOrCreate(
            [
                'name' => Role::SUPPORT_ADMIN,
                'tenant_id' => null,
            ],
            [
                'description' => 'Support operator',
                'level' => Role::LEVEL_SUPPORT_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_SUPPORT,
            ]
        );

        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'SupportTickets',
                    'category' => 'support',
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        if (method_exists($user, 'clearPermissionsCache')) {
            $user->clearPermissionsCache();
        }
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }

        return $user->fresh();
    }

    private function technicalRequestTypeId(): int
    {
        $id = SupportRequestType::query()->where('slug', 'technical_issue')->value('id');

        $this->assertNotNull($id, 'Support request type technical_issue must be seeded.');

        return (int) $id;
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'SupportTickets',
                    'category' => 'support',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        if (method_exists($user, 'clearPermissionsCache')) {
            $user->clearPermissionsCache();
        }
    }
}
