<?php

namespace Modules\Family\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\FamilyDashboardCache;
use Modules\Family\Testing\FamilyCertificationTestCase;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FamilyDashboardApiTest extends FamilyCertificationTestCase
{
    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson('/api/families/dashboard')->assertUnauthorized();
    }

    #[Test]
    public function it_requires_families_view_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->tenantUser($tenant->id)->create();
        Passport::actingAs($user);

        $this->getJson('/api/families/dashboard')->assertForbidden();
    }

    #[Test]
    public function it_denies_parishioners(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $this->authenticateAsParishioner($ctx['tenant'], $household['persons']['head']);

        $this->getJson('/api/families/dashboard')->assertForbidden();
    }

    #[Test]
    public function it_returns_population_kpis_and_excludes_other_tenants(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);
        $other = Tenant::factory()->create();
        $this->seedHousehold($other, ['family_name' => 'Hidden']);

        $response = $this->getJson('/api/families/dashboard')->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $response->json('data.kpis.total_families'));
        $this->assertSame(3, $response->json('data.kpis.total_people'));
        $this->assertSame(3, $response->json('data.kpis.active_people'));
        $this->assertArrayNotHasKey('contributions', $response->json('data'));
        $this->assertArrayNotHasKey('pastoral', $response->json('data'));
        $this->assertNotEmpty($response->json('data.definitions.total_people'));
        $this->assertNotEmpty($response->json('data.meta.generated_at'));
    }

    #[Test]
    public function it_separates_inactive_from_migrated_families(): void
    {
        $ctx = $this->authenticateAsStaff();
        $active = $this->seedHousehold($ctx['tenant']);
        $inactive = $this->seedHousehold($ctx['tenant'], ['family_name' => 'Inactive House']);
        $inactive['family']->update(['status' => 'inactive']);
        $migrated = $this->seedHousehold($ctx['tenant'], ['family_name' => 'Moved']);
        $migrated['family']->update(['status' => 'migrated']);

        $response = $this->getJson('/api/families/dashboard')->assertOk();

        $this->assertSame(3, $response->json('data.population.total_families'));
        $this->assertSame(1, $response->json('data.population.active_families'));
        $this->assertSame(1, $response->json('data.population.inactive_families'));
        $this->assertSame(1, $response->json('data.population.migrated_families'));
        $this->assertSame($active['family']->id ? 1 : 1, $response->json('data.kpis.active_families'));
    }

    #[Test]
    public function it_classifies_age_boundaries_and_excludes_missing_dob_from_percents(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);

        $this->addMember($family, ['date_of_birth' => '2024-06-15', 'gender' => 'male', 'relationship_to_head' => 'son']); // 2 babies
        $this->addMember($family, ['date_of_birth' => '2023-06-15', 'gender' => 'female', 'relationship_to_head' => 'daughter']); // 3 children
        $this->addMember($family, ['date_of_birth' => '2013-06-15', 'gender' => 'male']); // 13 teenagers
        $this->addMember($family, ['date_of_birth' => '2008-06-15', 'gender' => 'female']); // 18 young adults
        $this->addMember($family, ['date_of_birth' => '2000-06-15', 'gender' => 'male']); // 26 adults
        $this->addMember($family, ['date_of_birth' => '1966-06-15', 'gender' => 'female']); // 60 seniors
        $this->addMember($family, ['date_of_birth' => null, 'gender' => 'other']);

        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $ages = $response->json('data.demographics.age_groups');

        $this->assertSame(1, $ages['babies']['count']);
        $this->assertSame(1, $ages['children']['count']);
        $this->assertSame(1, $ages['teenagers']['count']);
        $this->assertSame(1, $ages['young_adults']['count']);
        $this->assertSame(1, $ages['adults']['count']);
        $this->assertSame(1, $ages['seniors']['count']);
        $this->assertSame(1, $ages['unknown']['count']);
        $this->assertEquals(0, $ages['unknown']['percent']);
        $this->assertEquals(16.7, $ages['adults']['percent']);
        $this->assertSame(1, $response->json('data.demographics.without_dob'));
        $this->assertSame(6, $response->json('data.demographics.with_dob'));
        $this->assertSame(3, $response->json('data.demographics.under_18'));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_uses_recorded_gender_as_percent_denominator(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->addMember($family, ['gender' => 'male']);
        $this->addMember($family, ['gender' => 'female']);
        $this->addMember($family, ['gender' => null]);

        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertEquals(50, $response->json('data.demographics.gender.male.percent'));
        $this->assertSame(1, $response->json('data.demographics.without_gender'));
        $this->assertEquals(0, $response->json('data.demographics.gender.unknown.percent'));
    }

    #[Test]
    public function it_counts_household_profile_and_active_family_head_correctly(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);

        $withHead = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertSame(0, $withHead->json('data.household.no_active_head'));
        $this->assertSame(1, $withHead->json('data.household.have_under_18'));
        $this->assertGreaterThanOrEqual(1, $withHead->json('data.household.head_gender.male'));

        $headless = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        FamilyMember::factory()->spouse()->active()->create([
            'family_id' => $headless->id,
            'relationship_to_head' => 'spouse',
        ]);

        FamilyDashboardCache::bump($ctx['tenant']->id);
        $mixed = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertSame(1, $mixed->json('data.household.no_active_head'));
        $this->assertSame(1, $mixed->json('data.attention.no_active_head'));
    }

    #[Test]
    public function it_counts_each_family_once_in_size_bands_and_lists_empty_households(): void
    {
        $ctx = $this->authenticateAsStaff();
        $one = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->addMember($one, ['relationship_to_head' => 'self', 'phone' => '111']);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'family_name' => 'Empty']);

        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertSame(1, $response->json('data.household.bands.1.count'));
        $this->assertSame(1, $response->json('data.household.families_without_members'));
        $this->assertSame(1, $response->json('data.attention.no_members'));

        $list = $this->getJson('/api/families?missing=members')->assertOk();
        $this->assertSame(1, $list->json('total'));
    }

    #[Test]
    public function it_charts_occupation_categories_and_drills_down_with_the_same_rules(): void
    {
        $ctx = $this->authenticateAsStaff();
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
            'bcc_id' => $bcc->id,
        ]);

        $rows = [
            'Student' => 'Student',
            'EngStudent' => 'Engineering Student',
            'Servant' => 'Government servant',
            'GovEng' => 'Government Engineer',
            'GovDoc' => 'Government Doctor',
            'GovTeach' => 'Government Teacher',
            'Soft' => 'Software engineer',
            'SoftCase' => '  SOFTWARE   ENGINEER ',
            'Doctor' => 'Doctor',
            'Lawyer' => 'Lawyer',
            'PrivEng' => 'Private Engineer',
            'PrivDoc' => 'Private Doctor',
            'Nurse' => 'Nurse',
            'Shop' => 'Shop owner',
            'Spark' => 'Electrician',
            'Driver' => 'Auto driver',
            'Parish' => 'Parish secretary',
            'ParishCase' => '  PARISH   SECRETARY ',
            'Home' => 'Homemaker',
            'Wife' => 'Housewife',
            'Retired' => 'Retired employee',
            'RetTeach' => 'Retired Government Teacher',
            'PrivTeach' => 'Private Teacher',
            'PrivSchool' => 'Private School Teacher',
            'ItStaff' => 'IT employee',
            'Books' => 'Accountant',
            'Teach' => 'School teacher',
            'Clerk' => 'Bank clerk',
            'Odd' => 'Unknown craft',
            'Abbrev' => 'BE',
            'Blank' => '   ',
            'None' => null,
        ];
        foreach ($rows as $name => $occupation) {
            $this->addMember($family, ['first_name' => $name, 'occupation' => $occupation]);
        }
        $removed = $this->addMember($family, ['first_name' => 'Gone', 'occupation' => 'Doctor']);
        $removed->delete();

        $other = Tenant::factory()->create();
        $otherFamily = Family::factory()->create(['tenant_id' => $other->id, 'status' => 'active']);
        $this->addMember($otherFamily, [
            'occupation' => 'Doctor',
            'tenant_id' => $other->id,
            'first_name' => 'Hidden',
        ]);

        $inactive = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'inactive',
            'bcc_id' => $bcc->id,
        ]);
        $this->addMember($inactive, ['occupation' => 'Shop owner', 'first_name' => 'InactiveShop']);

        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $occupation = $response->json('data.background.occupation');
        $byKey = collect($occupation['values'])->keyBy('key');
        $labels = collect($occupation['values'])->pluck('label');

        $this->assertSame(33, $occupation['total']);
        $this->assertSame(31, $occupation['recorded']);
        $this->assertSame(2, $occupation['not_recorded']);
        $this->assertSame(7, $occupation['unclassified']);
        $this->assertSame(33, collect($occupation['values'])->sum('count'));
        $this->assertSame(31, $occupation['recorded'] + 0);
        $this->assertSame($occupation['total'], $occupation['recorded'] + $occupation['not_recorded']);
        $this->assertSame(2, $byKey['student']['count']);
        $this->assertSame(4, $byKey['government_job']['count']);
        $this->assertSame(7, $byKey['professional']['count']);
        $this->assertSame(3, $byKey['private_job']['count']);
        $this->assertSame(2, $byKey['labour_skilled_trade']['count']);
        $this->assertSame(2, $byKey['business_self_employed']['count']);
        $this->assertSame(2, $byKey['former_retired']['count']);
        $this->assertSame(2, $byKey['homemaker']['count']);
        $this->assertSame(7, $byKey['unclassified']['count']);
        $this->assertSame(2, $byKey['not_recorded']['count']);
        $this->assertSame(6.1, $byKey['student']['percent']);
        $this->assertFalse($labels->contains('Software engineer'));
        $this->assertFalse($labels->contains('Other recorded'));
        $this->assertFalse($labels->contains('Parish secretary'));
        $this->assertFalse($labels->contains('Church / Parish Service'));
        $this->assertSame('Professional', $byKey['professional']['label']);
        $this->assertCount(10, $occupation['categories']);

        $active = $this->getJson('/api/families/dashboard?status=active&bcc_id='.$bcc->id)->assertOk();
        $activeOccupation = $active->json('data.background.occupation');
        $this->assertSame(32, $activeOccupation['total']);
        $this->assertSame(32, collect($activeOccupation['values'])->sum('count'));
        $this->assertSame(1, collect($activeOccupation['values'])->firstWhere('key', 'business_self_employed')['count']);

        $members = $this->getJson('/api/members?occupation='.urlencode('Professional').'&per_page=50')->assertOk();
        $this->assertSame(
            ['Doctor', 'Lawyer', 'Nurse', 'PrivDoc', 'PrivEng', 'Soft', 'SoftCase'],
            collect($members->json('data'))->pluck('first_name')->sort()->values()->all()
        );
        $this->assertSame(7, $members->json('total'));

        $byKeyFilter = $this->getJson('/api/members?occupation=professional&per_page=50')->assertOk();
        $this->assertSame(7, $byKeyFilter->json('total'));

        $unclassified = $this->getJson('/api/members?occupation='.urlencode('Other / Unclassified').'&per_page=50')->assertOk();
        $this->assertSame(
            ['Abbrev', 'Books', 'Clerk', 'Odd', 'Parish', 'ParishCase', 'Teach'],
            collect($unclassified->json('data'))->pluck('first_name')->sort()->values()->all()
        );

        $government = $this->getJson('/api/members?occupation='.urlencode('Government Job').'&per_page=50')->assertOk();
        $this->assertSame(
            ['GovDoc', 'GovEng', 'GovTeach', 'Servant'],
            collect($government->json('data'))->pluck('first_name')->sort()->values()->all()
        );

        $retired = $this->getJson('/api/members?occupation='.urlencode('Former / Retired').'&per_page=50')->assertOk();
        $this->assertSame(['RetTeach', 'Retired'], collect($retired->json('data'))->pluck('first_name')->sort()->values()->all());

        $missing = $this->getJson('/api/members?occupation='.urlencode('Not Recorded').'&per_page=50')->assertOk();
        $this->assertSame(['Blank', 'None'], collect($missing->json('data'))->pluck('first_name')->sort()->values()->all());

        $this->getJson('/api/members?occupation='.urlencode("'; DROP TABLE family_members; --"))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson('/api/members?tenant_id='.$other->id.'&occupation=professional&per_page=50')
            ->assertOk()
            ->assertJsonPath('total', 7);

        $this->getJson('/api/members?bcc_id='.$bcc->id.'&occupation='.urlencode('Labour / Skilled Trade').'&per_page=50')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function it_requires_authentication_for_occupation_drill_down(): void
    {
        $this->getJson('/api/members?occupation=Professional')->assertUnauthorized();
    }

    #[Test]
    public function family_status_filter_does_not_filter_member_status(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $household['spouse']->update(['status' => 'inactive']);

        $members = $this->getJson('/api/members?family_status=active&per_page=50')->assertOk();
        $ids = collect($members->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($household['spouse']->id));
        $this->assertTrue($ids->contains($household['head']->id));
    }

    #[Test]
    public function it_rejects_foreign_bcc_and_ignores_client_tenant_id(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);
        $other = Tenant::factory()->create();
        $foreignBcc = BCC::factory()->active()->create(['tenant_id' => $other->id]);

        $this->getJson('/api/families/dashboard?bcc_id='.$foreignBcc->id)
            ->assertStatus(422);

        $hidden = $this->seedHousehold($other);
        $response = $this->getJson('/api/families/dashboard?tenant_id='.$other->id);
        $this->assertTrue(in_array($response->status(), [200, 422], true));
        if ($response->status() === 200) {
            $this->assertSame(1, $response->json('data.kpis.total_families'));
            $this->assertNotEquals($hidden['family']->id, $response->json('data.growth.recent_families.0.id'));
        }
    }

    #[Test]
    public function it_includes_contributions_only_with_permission(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);
        $this->grantFamilyPermissions($ctx['user'], ['donations.view']);

        $with = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertArrayHasKey('contributions', $with->json('data'));
    }

    #[Test]
    public function refresh_bypasses_cache_after_version_bump(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->seedHousehold($ctx['tenant']);
        $first = $this->getJson('/api/families/dashboard')->assertOk();
        $this->assertFalse($first->json('data.meta.cached'));

        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'family_name' => 'Later']);
        FamilyDashboardCache::bump($ctx['tenant']->id);

        $refreshed = $this->getJson('/api/families/dashboard?refresh=1')->assertOk();
        $this->assertSame(2, $refreshed->json('data.kpis.total_families'));
    }

    #[Test]
    public function query_count_stays_bounded_on_a_larger_fixture(): void
    {
        $ctx = $this->authenticateAsStaff();
        for ($i = 0; $i < 40; $i++) {
            $this->seedHousehold($ctx['tenant'], ['family_name' => 'House '.$i]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(70, $count);
        $this->assertLessThan(150000, strlen((string) $response->getContent()));
    }

    #[Test]
    public function ui_scope_matches_kpis_and_uses_fewer_queries_than_full(): void
    {
        $ctx = $this->authenticateAsStaff();
        $this->grantFamilyPermissions($ctx['user'], ['donations.view', 'sacraments.view']);
        for ($i = 0; $i < 25; $i++) {
            $this->seedHousehold($ctx['tenant'], ['family_name' => 'Scope '.$i]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $full = $this->getJson('/api/families/dashboard?scope=full')->assertOk();
        $fullQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ui = $this->getJson('/api/families/dashboard?scope=ui')->assertOk();
        $uiQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($full->json('data.kpis.total_families'), $ui->json('data.kpis.total_families'));
        $this->assertSame($full->json('data.kpis.total_people'), $ui->json('data.kpis.total_people'));
        $this->assertSame(
            $full->json('data.demographics.age_groups'),
            $ui->json('data.demographics.age_groups')
        );
        $this->assertArrayNotHasKey('contributions', $ui->json('data'));
        $this->assertArrayNotHasKey('pastoral', $ui->json('data'));
        $this->assertLessThan($fullQueries, $uiQueries);
        $this->assertLessThan(strlen((string) $full->getContent()), strlen((string) $ui->getContent()));

        if (getenv('FAMILY_DASHBOARD_BENCH') === '1') {
            fwrite(
                STDERR,
                sprintf(
                    "family_dashboard_bench full_queries=%d ui_queries=%d full_bytes=%d ui_bytes=%d\n",
                    $fullQueries,
                    $uiQueries,
                    strlen((string) $full->getContent()),
                    strlen((string) $ui->getContent())
                )
            );
        }
    }

    #[Test]
    public function it_charts_qualification_level_and_drills_down_with_the_same_rules(): void
    {
        $ctx = $this->authenticateAsStaff();
        $bcc = BCC::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'active',
            'bcc_id' => $bcc->id,
        ]);

        $this->addMember($family, ['education' => 'B.Tech — Computer Science', 'first_name' => 'Tech']);
        $this->addMember($family, ['education' => 'B.Tech - Mechanical', 'first_name' => 'Mech']);
        $this->addMember($family, ['education' => 'ITI — Electrician', 'first_name' => 'Trade']);
        $this->addMember($family, ['education' => 'Plus Two — Science', 'first_name' => 'Stream']);
        $this->addMember($family, ['education' => 'B.Ed', 'first_name' => 'Teach']);
        $this->addMember($family, ['education' => 'M.Com', 'first_name' => 'Com']);
        $this->addMember($family, ['education' => 'Sacred Heart High School', 'first_name' => 'School']);
        $this->addMember($family, ['education' => 'Don Bosco HS', 'first_name' => 'Hs']);
        $this->addMember($family, ['education' => '   ', 'first_name' => 'Blank']);
        $this->addMember($family, ['education' => null, 'first_name' => 'None']);
        $this->addMember($family, ['education' => 'Bachelor of Science', 'first_name' => 'Ambiguous']);
        $removed = $this->addMember($family, ['education' => 'B.Tech', 'first_name' => 'Gone']);
        $removed->delete();

        $other = Tenant::factory()->create();
        $otherFamily = Family::factory()->create(['tenant_id' => $other->id, 'status' => 'active']);
        $this->addMember($otherFamily, ['education' => 'B.Tech', 'tenant_id' => $other->id, 'first_name' => 'Hidden']);

        $inactive = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'status' => 'inactive',
            'bcc_id' => $bcc->id,
        ]);
        $this->addMember($inactive, ['education' => 'M.Com', 'first_name' => 'InactiveCom']);

        $response = $this->getJson('/api/families/dashboard')->assertOk();
        $education = $response->json('data.background.education');
        $byLabel = collect($education['values'])->keyBy('label');

        $this->assertSame(12, $education['total']);
        $this->assertSame(7, $education['recorded']);
        $this->assertSame(2, $education['not_recorded']);
        $this->assertSame(3, $education['excluded']);
        $this->assertSame(12, collect($education['values'])->sum('count'));
        $this->assertSame(2, $byLabel['Professional Education']['count']);
        $this->assertSame(16.7, $byLabel['Professional Education']['percent']);
        $this->assertSame(3, $byLabel['Postgraduate (PG)']['count']);
        $this->assertSame(1, $byLabel['ITI / Vocational']['count']);
        $this->assertSame(1, $byLabel['School Education (Classes 1–12)']['count']);
        $this->assertSame(3, $byLabel['Other / Unclassified']['count']);
        $this->assertSame(2, $byLabel['Not Recorded']['count']);
        $this->assertFalse($byLabel->has('Sacred Heart High School'));
        $this->assertFalse($byLabel->has('B.Tech'));
        $this->assertFalse($byLabel->has('Computer Science'));
        $this->assertFalse($byLabel->has('Electrician'));
        $this->assertFalse($byLabel->has('Science'));

        $filtered = $this->getJson('/api/families/dashboard?status=active&bcc_id='.$bcc->id)->assertOk();
        $filteredEducation = $filtered->json('data.background.education');
        $filteredByLabel = collect($filteredEducation['values'])->keyBy('label');
        $this->assertSame(11, $filteredEducation['total']);
        $this->assertSame(6, $filteredEducation['recorded']);
        $this->assertSame(11, collect($filteredEducation['values'])->sum('count'));
        $this->assertSame(2, $filteredByLabel['Professional Education']['count']);
        $this->assertSame(2, $filteredByLabel['Postgraduate (PG)']['count']);

        $activeOnly = $this->getJson('/api/families/dashboard?status=active')->assertOk();
        $this->assertSame(2, collect($activeOnly->json('data.background.education.values'))->firstWhere('label', 'Postgraduate (PG)')['count']);

        $members = $this->getJson('/api/members?education='.urlencode('Professional Education').'&per_page=50')->assertOk();
        $names = collect($members->json('data'))->pluck('first_name')->sort()->values()->all();
        $this->assertSame(['Mech', 'Tech'], $names);

        $postgraduate = $this->getJson('/api/members?education='.urlencode('Postgraduate (PG)').'&per_page=50')->assertOk();
        $this->assertSame(
            ['Com', 'InactiveCom', 'Teach'],
            collect($postgraduate->json('data'))->pluck('first_name')->sort()->values()->all()
        );

        $exact = $this->getJson('/api/members?education='.urlencode('Bachelor of Science').'&per_page=50')->assertOk();
        $this->assertSame(['Ambiguous'], collect($exact->json('data'))->pluck('first_name')->all());

        $this->getJson('/api/members?education='.urlencode("'; DROP TABLE family_members; --"))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson('/api/members?bcc_id='.$bcc->id.'&education='.urlencode('ITI / Vocational').'&per_page=50')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_requires_authentication_for_education_drill_down(): void
    {
        $this->getJson('/api/members?education=B.Tech')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function addMember(Family $family, array $attrs): FamilyMember
    {
        return FamilyMember::factory()->create(array_merge([
            'family_id' => $family->id,
            'tenant_id' => $family->tenant_id,
            'status' => 'active',
        ], $attrs));
    }
}
