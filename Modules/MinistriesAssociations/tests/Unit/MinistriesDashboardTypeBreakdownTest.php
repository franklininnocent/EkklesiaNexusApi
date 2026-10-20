<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Services\MinistriesDashboardService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinistriesDashboardTypeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_by_type_lists_tenant_types_with_counts_including_zeros(): void
    {
        $tenant = Tenant::factory()->create();
        $userId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;

        $choirType = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'choir',
            'name' => 'Choir',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $fellowshipType = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'fellowship',
            'name' => 'Fellowship',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 2,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $category = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'spiritual',
            'name' => 'Spiritual',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type_id' => $choirType->id,
            'code' => 'CHOIR-1',
            'name' => 'Parish Choir',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type_id' => $fellowshipType->id,
            'code' => 'FEL-1',
            'name' => 'Youth Fellowship',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $summary = app(MinistriesDashboardService::class)->summary((int) $tenant->id);
        $byType = $summary['organizations']['active_by_type'];

        $this->assertSame(2, $summary['organizations']['active']);
        $this->assertCount(2, $byType);
        $this->assertSame('choir', $byType[0]['code']);
        $this->assertSame(1, $byType[0]['count']);
        $this->assertSame('fellowship', $byType[1]['code']);
        $this->assertSame(1, $byType[1]['count']);

        $byCategory = $summary['organizations']['category_breakdown'];
        $this->assertSame(2, $byCategory[0]['count']);
        $this->assertSame('spiritual', $byCategory[0]['code']);

        OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'committee',
            'name' => 'Committee',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 3,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $executive = app(MinistriesDashboardService::class)->executiveSummary((int) $tenant->id);
        $execTypes = $executive['organizations']['active_by_type'];
        $this->assertSame(2, $executive['organizations']['active']);
        $this->assertCount(2, $execTypes);
        $this->assertSame(['choir', 'fellowship'], array_column($execTypes, 'code'));
        $this->assertArrayNotHasKey('category_breakdown', $executive['organizations']);
    }

    #[Test]
    public function executive_summary_issues_fewer_queries_than_full_summary(): void
    {
        $tenant = Tenant::factory()->create();
        $userId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;
        $type = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'choir',
            'name' => 'Choir',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $category = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'spiritual',
            'name' => 'Spiritual',
            'is_system' => true,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'type_id' => $type->id,
            'code' => 'CHOIR-1',
            'name' => 'Parish Choir',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $service = app(MinistriesDashboardService::class);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $service->summary((int) $tenant->id);
        $summaryQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Modules\Tenants\Support\TenantCacheVersion::bump((int) $tenant->id);
        $service->executiveSummary((int) $tenant->id);
        $execQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertGreaterThan($execQueries, $summaryQueries);
        $this->assertLessThanOrEqual(8, $execQueries);
    }
}
