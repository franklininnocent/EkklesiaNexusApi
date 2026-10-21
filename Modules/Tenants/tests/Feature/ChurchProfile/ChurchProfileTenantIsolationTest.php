<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileTenantIsolationTest extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_not_leak_other_tenant_profile_on_list_reads(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedChurchProfile($ctx['tenant'], ['patron_name' => 'Our Patron']);
        $this->seedLeadershipRoles();

        $otherTenant = Tenant::factory()->active()->create();
        $this->seedChurchProfile($otherTenant, ['patron_name' => 'Foreign Patron']);

        $response = $this->getJson('/api/church-profile');
        $response->assertOk()->assertJsonPath('data.patron_name', 'Our Patron');
        $this->assertNotSame('Foreign Patron', $response->json('data.patron_name'));
    }

    #[Test]
    public function it_should_not_update_other_tenant_statistics_or_social_records(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit', 'church.settings.delete']);
        $otherTenant = Tenant::factory()->active()->create();

        $foreignStat = $this->seedStatistic($otherTenant, ['membership_count' => 500]);
        $foreignSocial = $this->seedSocial($otherTenant, ['username' => 'foreign']);

        $this->putJson("/api/church-statistics/{$foreignStat->id}", ['membership_count' => 1])
            ->assertNotFound();
        $this->deleteJson("/api/church-statistics/{$foreignStat->id}")
            ->assertNotFound();
        $this->putJson("/api/church-social-media/{$foreignSocial->id}", ['username' => 'hacked'])
            ->assertNotFound();
        $this->deleteJson("/api/church-social-media/{$foreignSocial->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('church_statistics', [
            'id' => $foreignStat->id,
            'membership_count' => 500,
        ]);
        $this->assertDatabaseHas('church_social_media', [
            'id' => $foreignSocial->id,
            'username' => 'foreign',
        ]);
    }

    #[Test]
    public function it_should_not_terminate_other_tenant_leadership_assignment(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();

        $otherTenant = Tenant::factory()->active()->create();
        $otherProfile = $this->seedChurchProfile($otherTenant);
        $otherPerson = $this->makePerson($otherTenant, 'Foreign', 'Leader');
        $pastorRole = LeadershipRole::query()->where('title', 'Pastor')->firstOrFail();

        $foreignAssignment = LeadershipAssignment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'church_profile_id' => $otherProfile->id,
            'person_id' => $otherPerson->id,
            'role_id' => $pastorRole->id,
        ]);

        $this->putJson("/api/church-profile/leadership/assignments/{$foreignAssignment->id}/terminate", [
            'end_date' => now()->toDateString(),
            'exit_reason_code' => 'completed',
        ])->assertNotFound();

        $this->assertDatabaseHas('leadership_assignments', [
            'id' => $foreignAssignment->id,
            'end_date' => null,
        ]);
    }

    #[Test]
    public function it_should_exclude_other_tenant_records_from_statistics_and_social_lists(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.view', 'church.settings.edit']);
        $this->seedStatistic($ctx['tenant']);
        $otherTenant = Tenant::factory()->active()->create();
        $this->seedStatistic($otherTenant, ['year' => 2025, 'month' => 12]);
        $this->seedSocial($ctx['tenant']);
        $this->seedSocial($otherTenant, ['platform' => 'twitter', 'url' => 'https://twitter.com/other']);

        $stats = $this->getJson('/api/church-statistics')->assertOk();
        $this->assertSame(1, $stats->json('total'));

        $social = $this->getJson('/api/church-social-media')->assertOk();
        $this->assertSame(1, $social->json('total'));
    }

    #[Test]
    public function it_should_leave_foreign_profile_unmodified_after_cross_tenant_update_attempt(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $otherTenant = Tenant::factory()->active()->create();
        $foreignProfile = $this->seedChurchProfile($otherTenant, [
            'about' => 'Sacred foreign about',
            'patron_name' => 'St Foreign',
        ]);
        $snapshot = $this->snapshotProfile($foreignProfile);

        $this->putJson('/api/church-profile', $this->validProfilePayload(['about' => 'Hacked about']))
            ->assertOk();

        $this->assertDatabaseHas('church_profiles', $snapshot);
        $this->assertDatabaseMissing('church_profiles', [
            'id' => $foreignProfile->id,
            'about' => 'Hacked about',
        ]);
    }
}
