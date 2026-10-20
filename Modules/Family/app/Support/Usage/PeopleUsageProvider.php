<?php

namespace Modules\Family\Support\Usage;

use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Contracts\UsageMetricProvider;

/**
 * Active parish memberships that consume PEOPLE_LIMIT (not historical on-record totals).
 */
final class PeopleUsageProvider implements UsageMetricProvider
{
    public function metricCode(): string
    {
        return 'PEOPLE_LIMIT';
    }

    public function currentUsage(int $tenantId): int
    {
        return (int) FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->consumingPeopleLimit()
            ->count();
    }
}
