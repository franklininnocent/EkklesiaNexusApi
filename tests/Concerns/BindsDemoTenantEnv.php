<?php

namespace Tests\Concerns;

use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;

trait BindsDemoTenantEnv
{
    protected function bindDemoTenantEnv(int $tenantId): void
    {
        $key = TenantDemoMarkers::ENV_TENANT_ID;
        $value = (string) $tenantId;
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
