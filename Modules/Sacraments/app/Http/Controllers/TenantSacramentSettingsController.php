<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\UpdateTenantSacramentSettingRequest;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Tenants\Http\Concerns\VerifiesParishProductAccess;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\TenantContext;

class TenantSacramentSettingsController extends Controller
{
    use VerifiesParishProductAccess;

    public function __construct(
        protected TenantSacramentSettingsService $settings
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->verifyTenantUser($request)) {
            return $error;
        }

        if ($error = $this->authorizeSettings($request, manage: false)) {
            return $error;
        }

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $rows = $this->settings->listForTenant($tenantId, $request->user()?->id);

            return response()->json([
                'success' => true,
                'data' => $rows,
                'message' => 'Sacrament settings retrieved successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error listing tenant sacrament settings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament settings',
            ], 500);
        }
    }

    public function update(UpdateTenantSacramentSettingRequest $request, int $sacramentType): JsonResponse
    {
        if ($error = $this->verifyTenantUser($request)) {
            return $error;
        }

        if ($error = $this->authorizeSettings($request, manage: true)) {
            return $error;
        }

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $row = $this->settings->updateAvailability(
                $tenantId,
                $sacramentType,
                $request->boolean('is_active'),
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'data' => $row,
                'message' => $row['is_active']
                    ? 'Sacrament activated for this church'
                    : 'Sacrament deactivated for this church',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating tenant sacrament setting', [
                'sacrament_type_id' => $sacramentType,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament settings',
            ], 500);
        }
    }

    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        return $this->verifyParishProductUser(
            $request->user(),
            'Unauthorized. Only tenant users can manage sacrament settings.',
            'Ekklesia users cannot access tenant sacrament settings without an active Support Center session.',
        );
    }

    private function authorizeSettings(Request $request, bool $manage): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
            ], 403);
        }

        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)) {
            return null;
        }

        $isAdmin = method_exists($user, 'isTenantAdmin') && $user->isTenantAdmin();
        $canView = $isAdmin
            || (method_exists($user, 'hasPermission') && (
                $user->hasPermission('sacraments.settings.view')
                || $user->hasPermission('sacraments.settings.manage')
            ));
        $canManage = $isAdmin
            || (method_exists($user, 'hasPermission') && $user->hasPermission('sacraments.settings.manage'));

        if ($manage ? ! $canManage : ! $canView) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
            ], 403);
        }

        return null;
    }
}
