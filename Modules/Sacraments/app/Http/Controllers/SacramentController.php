<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\BulkUpdateSacramentStatusRequest;
use Modules\Sacraments\Http\Requests\CorrectSacramentRequest;
use Modules\Sacraments\Http\Requests\PatchSacramentMetadataRequest;
use Modules\Sacraments\Http\Requests\RestoreSacramentRequest;
use Modules\Sacraments\Http\Requests\StoreSacramentRequest;
use Modules\Sacraments\Http\Requests\UpdateSacramentRequest;
use Modules\Sacraments\Http\Requests\VoidSacramentRequest;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Sacraments\Support\MarriageRegisterFilter;
use Modules\Sacraments\Support\SacramentFeatureFlags;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Http\Concerns\VerifiesParishProductAccess;
use Modules\Tenants\Support\TenantContext;

/**
 * SacramentController - Tenant Sacrament Records Management
 *
 * Phase 1: Form Requests, RBAC via routes, no silent FamilyMember sync (ADR-13),
 * status registered/conditional/voided (ADR-07).
 */
class SacramentController extends Controller
{
    use VerifiesParishProductAccess;

    public function __construct(
        protected SacramentService $service,
        protected SacramentPrivacyAccess $privacyAccess
    ) {}

    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        return $this->verifyParishProductUser(
            $request->user(),
            'Unauthorized. Only tenant users can manage sacrament records.',
            'Ekklesia users cannot access tenant sacrament records without an active Support Center session.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $params = $request->only([
                'page', 'sacrament_type_id', 'status', 'search',
                'date_from', 'date_to', 'per_page', 'sort_by', 'sort_dir',
                'minister_name', 'certificate_number', 'book_number',
                'family_id', 'bcc_id', 'event_subtype', 'family_member_id',
                'marriage_register_filter',
            ]);

            if (isset($params['page'])) {
                $params['page'] = max(1, (int) $params['page']);
            }

            if (isset($params['per_page'])) {
                $params['per_page'] = max(1, min(100, (int) $params['per_page']));
            }

            if (isset($params['status'])) {
                $params['status'] = SacramentStatus::normalize($params['status']) ?? $params['status'];
            }

            if (! empty($params['marriage_register_filter'])) {
                $normalizedFilter = MarriageRegisterFilter::normalize($params['marriage_register_filter']);
                if ($normalizedFilter === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid marriage register filter.',
                    ], 422);
                }

                $params['marriage_register_filter'] = $normalizedFilter;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $params['tenant_id'] = $tenantId;

            if (! empty($params['family_member_id'])) {
                $member = FamilyMember::query()
                    ->where('id', $params['family_member_id'])
                    ->whereHas('family', fn ($query) => $query->where('tenant_id', $tenantId))
                    ->first();

                if (! $member) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Family member not found',
                    ], 404);
                }

                $params['family_member_id'] = $member->id;
                if ($member->person_id) {
                    $params['linked_person_id'] = $member->person_id;
                }
            }
            $params['include_restricted'] = $this->privacyAccess->canViewRestricted($request->user());

            $sacraments = $this->service->getAll($params);

            return response()->json([
                'success' => true,
                'data' => $sacraments,
                'message' => 'Sacraments retrieved successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacraments', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacraments',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $sacrament = $this->service->getByIdForTenant($id, $tenantId);

            if (! $sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found',
                ], 404);
            }

            $this->authorize('view', $sacrament);

            $this->privacyAccess->assertCanAccessSacrament($request->user(), $sacrament);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament retrieved successfully',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament',
            ], 500);
        }
    }

    public function store(StoreSacramentRequest $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $validated = $request->validated();

            // Drop alias field if present — DB column is recipient_birth_date.
            unset($validated['recipient_dob']);

            $validated['tenant_id'] = app(TenantContext::class)->requireEffectiveTenantId();
            $validated['created_by'] = $user->id;
            $validated['status'] = SacramentStatus::normalize($validated['status'] ?? null)
                ?? SacramentStatus::REGISTERED;

            // ADR-13: do not silently mutate FamilyMember from sacrament create.
            $result = $this->service->create(
                $validated,
                $request->header('Idempotency-Key')
            );

            $sacrament = $result['sacrament'];
            $status = $result['replay'] ? 200 : 201;
            $message = $result['replay']
                ? 'Sacrament already created for this Idempotency-Key'
                : 'Sacrament created successfully';

            Log::info('Sacrament record created', [
                'sacrament_id' => $sacrament->id,
                'tenant_id' => $validated['tenant_id'],
                'created_by' => $user->id,
                'replay' => $result['replay'],
                'participants_v1' => SacramentFeatureFlags::participantsV1Enabled(),
            ]);

            $payload = [
                'success' => true,
                'data' => $sacrament,
                'message' => $message,
                'replay' => $result['replay'],
            ];
            if ($result['warning']) {
                $payload['warning'] = $result['warning'];
            }

            return response()->json($payload, $status);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating sacrament', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create sacrament',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the sacrament record',
            ], 500);
        }
    }

    public function update(UpdateSacramentRequest $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $existingSacrament = $this->service->getByIdForTenant($id, $tenantId);

            if (! $existingSacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found',
                ], 404);
            }

            $this->privacyAccess->assertCanAccessSacrament($request->user(), $existingSacrament);

            $validated = $request->validated();
            unset($validated['recipient_dob']);

            if (array_key_exists('status', $validated)) {
                $validated['status'] = SacramentStatus::normalize($validated['status']) ?? $validated['status'];
            }

            $validated['updated_by'] = $user->id;

            // ADR-13: no silent FamilyMember sync on update.
            $sacrament = $this->service->update($id, $validated);

            Log::info('Sacrament record updated', [
                'sacrament_id' => $id,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament updated successfully',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating sacrament', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament',
            ], 500);
        }
    }

    public function correct(CorrectSacramentRequest $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $existing = $this->service->getByIdForTenant($id, $tenantId);
            if (! $existing) {
                return response()->json(['success' => false, 'message' => 'Sacrament not found'], 404);
            }
            $this->privacyAccess->assertCanAccessSacrament($request->user(), $existing);

            $sacrament = $this->service->correct(
                $id,
                $request->validated(),
                $tenantId,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament corrected successfully',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error correcting sacrament', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to correct sacrament',
            ], 500);
        }
    }

    public function void(VoidSacramentRequest $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $sacrament = $this->service->void(
                $id,
                $request->validated(),
                $tenantId,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament voided successfully',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error voiding sacrament', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to void sacrament',
            ], 500);
        }
    }

    public function restore(RestoreSacramentRequest $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $sacrament = $this->service->restore($id, $tenantId, $request->user()->id);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament restored successfully (soft-delete cleared; status unchanged)',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error restoring sacrament', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore sacrament',
            ], 500);
        }
    }

    public function patchMetadata(PatchSacramentMetadataRequest $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $sacrament = $this->service->patchMetadata(
                $id,
                $request->validated(),
                $tenantId,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament metadata updated successfully',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error patching sacrament metadata', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament metadata',
            ], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $sacrament = $this->service->getByIdForTenant($id, $tenantId);

            if (! $sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found',
                ], 404);
            }

            $this->privacyAccess->assertCanAccessSacrament($request->user(), $sacrament);

            $this->service->delete($id);

            Log::info('Sacrament record deleted', [
                'sacrament_id' => $id,
                'deleted_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting sacrament', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacrament',
            ], 500);
        }
    }

    public function getSacramentTypes(Request $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $settings = app(TenantSacramentSettingsService::class);
            $enabledMap = $settings->enabledMap($tenantId);
            $includeInactive = $request->boolean('include_inactive');
            $disabledIds = $includeInactive ? [] : $settings->disabledTypeIdsForTenant($tenantId);

            $types = SacramentType::query()
                ->where('active', true)
                ->when($disabledIds !== [], fn ($query) => $query->whereNotIn('id', $disabledIds))
                ->orderBy('display_order')
                ->orderBy('name')
                ->get()
                ->map(function (SacramentType $type) use ($enabledMap) {
                    $enabled = $enabledMap[(int) $type->id] ?? true;
                    $type->setAttribute('canonical_code', SacramentTypeCode::normalize($type->code));
                    $type->setAttribute('enabled_for_tenant', $enabled);

                    return $type;
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $types,
                'message' => 'Sacrament types retrieved successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament types', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament types',
            ], 500);
        }
    }

    /**
     * Authoritative sacrament definitions for FE (ADR-06). Phase 3.
     */
    public function getDefinitions(Request $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $definitions = app(SacramentDefinitionRegistry::class)->all();

            return response()->json([
                'success' => true,
                'data' => $definitions,
                'message' => 'Sacrament definitions retrieved successfully',
                'meta' => [
                    'participants_v1' => SacramentFeatureFlags::participantsV1Enabled(),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching sacrament definitions', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament definitions',
            ], 500);
        }
    }

    public function bulkUpdateStatus(BulkUpdateSacramentStatusRequest $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $validated = $request->validated();
            $status = SacramentStatus::normalize($validated['status']) ?? $validated['status'];
            $ids = $validated['ids'];

            // Only update rows owned by this tenant.
            $ownedIds = $this->service->getByIds($ids, $tenantId)
                ->pluck('id')
                ->all();

            if (count($ownedIds) !== count($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found',
                ], 404);
            }

            $updated = $this->service->bulkUpdateStatus($ownedIds, $status, $user->id);

            return response()->json([
                'success' => true,
                'data' => ['updated' => $updated],
                'message' => 'Sacrament statuses updated successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error bulk updating sacrament status', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament statuses',
            ], 500);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:sacraments,id',
            ]);

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $ids = $validated['ids'];

            $ownedIds = $this->service->getByIds($ids, $tenantId)
                ->pluck('id')
                ->all();

            if (count($ownedIds) !== count($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found',
                ], 404);
            }

            $deleted = $this->service->bulkDelete($ownedIds);

            return response()->json([
                'success' => true,
                'data' => ['deleted' => $deleted],
                'message' => 'Sacraments deleted successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error bulk deleting sacraments', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacraments',
            ], 500);
        }
    }
}
