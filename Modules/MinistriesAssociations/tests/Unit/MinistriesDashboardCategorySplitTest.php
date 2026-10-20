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

class MinistriesDashboardCategorySplitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_by_category_counts_sum_to_active_organizations(): void
    {
        $tenant = Tenant::factory()->create();
        $userId = (int) User::factory()->create(['tenant_id' => $tenant->id])->id;

        $ministryCat = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'ministry',
            'name' => 'Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $associationCat = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'association',
            'name' => 'Association',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 2,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $otherCat = OrganizationCategory::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'committee',
            'name' => 'Committee',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 3,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $type = OrganizationType::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'PARISH',
            'name' => 'Parish Ministry',
            'is_system' => false,
            'is_active' => true,
            'display_order' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $ministryCat->id,
            'type_id' => $type->id,
            'code' => 'MIN-1',
            'name' => 'Ministry One',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $associationCat->id,
            'type_id' => $type->id,
            'code' => 'ASC-1',
            'name' => 'Association One',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $otherCat->id,
            'type_id' => $type->id,
            'code' => 'OTH-1',
            'name' => 'Other Org',
            'status' => Organization::STATUS_ACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        Organization::query()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $ministryCat->id,
            'type_id' => $type->id,
            'code' => 'MIN-INACTIVE',
            'name' => 'Inactive Ministry',
            'status' => Organization::STATUS_INACTIVE,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $summary = app(MinistriesDashboardService::class)->summary((int) $tenant->id);
        $byCat = $summary['organizations']['active_by_category'];
        $sum = (int) $byCat['ministry'] + (int) $byCat['association'] + (int) $byCat['other'];

        $this->assertSame(3, $summary['organizations']['active']);
        $this->assertSame(1, $byCat['ministry']);
        $this->assertSame(1, $byCat['association']);
        $this->assertSame(1, $byCat['other']);
        $this->assertSame($summary['organizations']['active'], $sum);
    }
}
