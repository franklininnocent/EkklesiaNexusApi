<?php

namespace Modules\Subscriptions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Subscriptions\Services\SubscriptionAuditService;
use Modules\Subscriptions\Support\SubscriptionsPermissionCatalog;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-specific entitlement overrides may only be granted or revoked by platform Super Admin.
 */
class EnsureSuperAdminEntitlementOverride
{
    public function __construct(private readonly SubscriptionAuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        if ($user->tenant_id !== null) {
            $this->audit->denial('platform_access_denied', (int) $user->tenant_id, [
                'path' => $request->path(),
                'required' => [SubscriptionsPermissionCatalog::OVERRIDES_MANAGE],
            ]);

            return response()->json([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Only Ekklesia platform administrators can manage subscription overrides.',
            ], 403);
        }

        if (! $user->isSuperAdmin() || ! $user->hasPermission(SubscriptionsPermissionCatalog::OVERRIDES_MANAGE)) {
            $this->audit->denial('platform_permission_denied', null, [
                'path' => $request->path(),
                'required' => [SubscriptionsPermissionCatalog::OVERRIDES_MANAGE, 'SuperAdmin'],
            ]);

            return response()->json([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Only Super Admin can grant or revoke tenant entitlement overrides.',
            ], 403);
        }

        return $next($request);
    }
}
