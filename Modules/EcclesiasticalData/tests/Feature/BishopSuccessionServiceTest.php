<?php

namespace Modules\EcclesiasticalData\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Services\BishopAppointmentBackfillService;
use Modules\EcclesiasticalData\Services\BishopDuplicateDetectionService;
use Modules\EcclesiasticalData\Services\BishopService;
use Modules\EcclesiasticalData\Services\BishopUpdateRequestService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Services\EpiscopalAppointmentService;
use Modules\EcclesiasticalData\Services\SuccessionService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestStatus;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Support\DioceseLeadershipState;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @group ecclesiastical
 * @group bishop-succession
 */
class BishopSuccessionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DioceseManagement $diocese;

    protected SuccessionService $successionService;

    protected EpiscopalAppointmentService $appointmentService;

    protected DioceseLeadershipQueryService $leadershipQuery;

    protected BishopService $bishopService;

    protected BishopUpdateRequestService $requestService;

    protected BishopAppointmentBackfillService $backfillService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Denomination::count()) {
            Denomination::create([
                'name' => 'Roman Catholic',
                'code' => 'RC',
                'description' => 'Roman Catholic Church',
                'status' => 'active',
            ]);
        }

        if (! Country::where('iso2', 'IN')->exists()) {
            Country::create([
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phone_code' => '+91',
                'status' => 'active',
            ]);
        }

        $this->diocese = DioceseManagement::factory()->create();
        $this->successionService = app(SuccessionService::class);
        $this->appointmentService = app(EpiscopalAppointmentService::class);
        $this->leadershipQuery = app(DioceseLeadershipQueryService::class);
        $this->bishopService = app(BishopService::class);
        $this->requestService = app(BishopUpdateRequestService::class);
        $this->backfillService = app(BishopAppointmentBackfillService::class);
    }

    #[Test]
    public function it_should_create_first_ordinary_and_mark_diocese_occupied(): void
    {
        $bishop = $this->bishopService->createPerson([
            'full_name' => 'Bishop Alpha',
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);

        $result = $this->successionService->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => '2018-01-01', 'installed_date' => '2018-02-01'],
        );

        $this->assertTrue($result['created']->is_current);
        $this->assertEquals(DioceseLeadershipState::Occupied, $this->diocese->fresh()->leadership_state);
    }

    #[Test]
    public function it_should_replace_current_ordinary_and_preserve_history(): void
    {
        $bishopA = $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $bishopB = $this->bishopService->createPerson([
            'full_name' => 'Bishop Beta',
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);

        $result = $this->successionService->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishopB,
            ['effective_date' => '2026-01-01'],
        );

        $this->assertNotNull($result['ended']);
        $this->assertFalse($result['ended']->is_current);
        $this->assertEquals('2025-12-31', $result['ended']->ended_date?->toDateString());
        $this->assertTrue($result['created']->is_current);

        $onDate = $this->leadershipQuery->getOrdinaryOnDate($this->diocese->id, '2020-01-01');
        $this->assertEquals($bishopA->id, $onDate?->bishop_id);
        $this->assertEquals($bishopB->id, $this->leadershipQuery->getOrdinaryOnDate($this->diocese->id, '2026-06-01')?->bishop_id);
    }

    #[Test]
    public function it_should_allow_auxiliary_bishop_with_current_ordinary(): void
    {
        $ordinary = $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $auxiliary = $this->bishopService->createPerson([
            'full_name' => 'Bishop Auxiliary',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $appointment = $this->appointmentService->create([
            'bishop_id' => $auxiliary->id,
            'diocese_id' => $this->diocese->id,
            'canonical_role' => CanonicalRole::Auxiliary->value,
            'appointed_date' => '2020-01-01',
            'effective_date' => '2020-01-01',
            'is_current' => true,
        ]);

        $leadership = $this->leadershipQuery->getCurrentLeadership($this->diocese->id);
        $this->assertEquals($ordinary->id, $leadership['ordinary']['bishop_id']);
        $this->assertCount(2, $leadership['current_appointments']);
        $this->assertEquals(CanonicalRole::Auxiliary->value, $appointment->canonical_role->value);
    }

    #[Test]
    public function it_should_not_replace_ordinary_when_coadjutor_is_appointed(): void
    {
        $ordinary = $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $coadjutor = $this->bishopService->createPerson([
            'full_name' => 'Bishop Coadjutor',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $this->appointmentService->create([
            'bishop_id' => $coadjutor->id,
            'diocese_id' => $this->diocese->id,
            'canonical_role' => CanonicalRole::Coadjutor->value,
            'appointed_date' => '2024-01-01',
            'effective_date' => '2024-01-01',
            'is_current' => true,
        ]);

        $leadership = $this->leadershipQuery->getCurrentLeadership($this->diocese->id);
        $this->assertEquals($ordinary->id, $leadership['ordinary']['bishop_id']);
        $this->assertTrue(
            BishopAppointment::query()
                ->where('bishop_id', $ordinary->id)
                ->where('is_current', true)
                ->ordinary()
                ->exists()
        );
    }

    #[Test]
    public function it_should_mark_diocese_vacant_when_ordinary_ends_without_successor(): void
    {
        $bishop = $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $appointment = BishopAppointment::query()->where('bishop_id', $bishop->id)->firstOrFail();

        $this->appointmentService->end($appointment, '2026-01-01', \Modules\EcclesiasticalData\Support\AppointmentEndReason::Retirement);

        $this->assertEquals(DioceseLeadershipState::Vacant, $this->diocese->fresh()->leadership_state);
        $this->assertNull($this->leadershipQuery->getCurrentLeadership($this->diocese->id)['ordinary']);
    }

    #[Test]
    public function it_should_keep_future_appointment_non_current_until_effective(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $bishopB = $this->bishopService->createPerson([
            'full_name' => 'Bishop Future',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $future = $this->appointmentService->create([
            'bishop_id' => $bishopB->id,
            'diocese_id' => $this->diocese->id,
            'canonical_role' => CanonicalRole::DiocesanBishop->value,
            'appointed_date' => now()->addMonths(3)->toDateString(),
            'effective_date' => now()->addMonths(3)->toDateString(),
            'is_current' => true,
        ]);

        $this->assertFalse($future->is_current);
        $this->assertEquals('bishop alpha', strtolower($this->leadershipQuery->getCurrentLeadership($this->diocese->id)['ordinary']['bishop_name']));
    }

    private function createChurchProfile(int $tenantId): ChurchProfile
    {
        return ChurchProfile::query()->create([
            'tenant_id' => $tenantId,
            'archdiocese_id' => $this->diocese->id,
        ]);
    }

    #[Test]
    public function it_should_not_apply_church_request_before_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $this->createChurchProfile($tenant->id);

        $actorId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;
        $request = $this->requestService->createDraft(
            $tenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Submitted'],
            ['effective_date' => '2026-03-01'],
            $actorId,
        );

        $this->requestService->submit($request->id, $tenant->id, (int) $request->fresh()->version, $actorId);

        $this->assertDatabaseMissing('bishops', ['full_name' => 'Bishop Submitted']);
        $this->assertEquals(BishopUpdateRequestStatus::Submitted, $request->fresh()->status);
    }

    #[Test]
    public function it_should_apply_approved_request_transactionally(): void
    {
        $this->createOrdinary('Bishop Alpha', '2018-01-01');
        $tenant = Tenant::factory()->create();
        $this->createChurchProfile($tenant->id);

        $actorId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;
        $request = $this->requestService->createDraft(
            $tenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Approved'],
            ['effective_date' => '2026-03-01'],
            $actorId,
        );
        $submitted = $this->requestService->submit($request->id, $tenant->id, (int) $request->fresh()->version, $actorId);

        $reviewer = User::factory()->create();
        $applied = $this->requestService->approveAndApply($submitted->id, (int) $submitted->version, $reviewer->id);

        $this->assertEquals(BishopUpdateRequestStatus::Applied, $applied->status);
        $this->assertEquals(
            'Bishop Approved',
            $this->leadershipQuery->getCurrentLeadership($this->diocese->id)['ordinary']['bishop_name']
        );
    }

    #[Test]
    public function it_should_reject_stale_concurrent_approval(): void
    {
        $tenant = Tenant::factory()->create();
        $this->createChurchProfile($tenant->id);

        $actorId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;
        $request = $this->requestService->createDraft(
            $tenant->id,
            $this->diocese->id,
            BishopUpdateRequestType::ChangeCurrentBishop,
            ['full_name' => 'Bishop Stale'],
            ['effective_date' => '2026-03-01'],
            $actorId,
        );
        $expectedVersion = (int) $request->fresh()->version;
        $submitted = $this->requestService->submit($request->id, $tenant->id, $expectedVersion, $actorId);

        $reviewer = User::factory()->create();
        $this->expectException(EcclesiasticalDomainException::class);
        $this->requestService->approveAndApply($submitted->id, $expectedVersion, (int) $reviewer->id);
    }

    #[Test]
    public function it_should_detect_potential_duplicate_bishops(): void
    {
        $this->bishopService->createPerson([
            'full_name' => 'John Paul Example',
            'date_of_birth' => '1960-05-01',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $duplicates = app(BishopDuplicateDetectionService::class)->findPotentialDuplicates([
            'full_name' => 'Most Rev. John Paul Example',
            'date_of_birth' => '1960-05-01',
        ]);

        $this->assertCount(1, $duplicates);
    }

    #[Test]
    public function succession_refreshes_only_the_affected_diocese_leadership_state(): void
    {
        $otherDiocese = DioceseManagement::factory()->create([
            'leadership_state' => DioceseLeadershipState::Vacant,
        ]);

        $this->createOrdinary('Bishop Alpha', '2018-01-01');

        $successor = $this->bishopService->createPerson([
            'full_name' => 'Bishop Beta',
            'archdiocese_id' => $this->diocese->id,
        ], null, false);

        $this->successionService->replaceCurrentOrdinary(
            $this->diocese->id,
            $successor,
            ['effective_date' => '2026-01-01'],
        );

        $this->assertEquals(DioceseLeadershipState::Occupied, $this->diocese->fresh()->leadership_state);
        $this->assertEquals(DioceseLeadershipState::Vacant, $otherDiocese->fresh()->leadership_state);
    }

    private function createOrdinary(string $name, string $effectiveDate): BishopManagement
    {
        $bishop = $this->bishopService->createPerson([
            'full_name' => $name,
            'archdiocese_id' => $this->diocese->id,
            'status' => 'active',
        ], null, false);

        $this->successionService->replaceCurrentOrdinary(
            $this->diocese->id,
            $bishop,
            ['effective_date' => $effectiveDate],
        );

        return $bishop->fresh();
    }
}
