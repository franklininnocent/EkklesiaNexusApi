<?php

namespace Modules\RolesAndPermissions\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Http\Requests\StoreTenantRoleRequest;
use Modules\RolesAndPermissions\Http\Requests\UpdateTenantRoleRequest;
use Modules\RolesAndPermissions\Services\PermissionAuditService;
use Modules\RolesAndPermissions\Services\TenantRoleService;
use Modules\RolesAndPermissions\Traits\EnforcesTenantIsolation;

class RolesAndPermissionsController extends Controller
{
    use EnforcesTenantIsolation;

    protected PermissionAuditService $auditService;

    protected TenantRoleService $tenantRoleService;

    public function __construct(PermissionAuditService $auditService, TenantRoleService $tenantRoleService)
    {
        $this->auditService = $auditService;
        $this->tenantRoleService = $tenantRoleService;
    }

    /**
     * List all roles (with pagination and filters).
     * - SuperAdmin sees all roles (global + all tenant roles)
     * - EkklesiaAdmin/Manager sees only their tenant's roles + global roles
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (! auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();

            $query = Role::query();

            $scopeDenied = $this->applyRoleListScope($query, $user);
            if ($scopeDenied !== null) {
                return $scopeDenied;
            }

            // Apply additional filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('tenant_id')) {
                if ($user->isSuperAdmin()) {
                    $query->where('tenant_id', $request->tenant_id);
                }
            }

            if ($request->has('is_custom')) {
                $query->where('is_custom', $request->is_custom);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Pagination
            $perPage = $request->get('per_page', 15);

            // Handle 'all' case to return all records without pagination
            if ($perPage === 'all') {
                $roles = $query->with('tenant')
                    ->withCount(['permissions'])
                    ->orderBy('level')
                    ->orderBy('created_at', 'desc')
                    ->get();

                $roles->each(function (Role $role): void {
                    $role->users_count = $role->assignedUsersCount();
                });

                return response()->json([
                    'success' => true,
                    'data' => $roles,
                    'total' => $roles->count(),
                ]);
            }

            $roles = $query->with('tenant')
                ->withCount(['permissions'])
                ->orderBy('level')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $roles->getCollection()->transform(function (Role $role): Role {
                $role->users_count = $role->assignedUsersCount();

                return $role;
            });

            return response()->json([
                'success' => true,
                'data' => $roles->items(),
                'total' => $roles->total(),
                'pagination' => [
                    'current_page' => $roles->currentPage(),
                    'last_page' => $roles->lastPage(),
                    'per_page' => $roles->perPage(),
                    'from' => $roles->firstItem(),
                    'to' => $roles->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching roles: '.$e->getMessage());

            return response()->json([
                'message' => 'Error fetching roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific role.
     */
    public function show($id): JsonResponse
    {
        try {
            if (! auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();
            $role = Role::with(['tenant', 'users'])->findOrFail($id);

            // Check authorization
            if (! $this->canViewRole($role)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json([
                'role' => $role,
                'stats' => [
                    'total_users' => $role->assignedUsersCount(),
                    'active_users' => $role->assignedUsersQuery()->where('active', 1)->distinct('users.id')->count('users.id'),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching role: '.$e->getMessage());

            return response()->json([
                'message' => 'Role not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create a new role.
     * - SuperAdmin can create global roles or tenant-specific roles
     * - EkklesiaAdmin can create tenant-specific roles for their tenant only
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'level' => 'required|integer|min:1|max:10',
                'tenant_id' => 'nullable|exists:tenants,id',
                'is_custom' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();
            $data = [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'level' => (int) $validated['level'],
                'active' => 1,
            ];

            // Authorization checks
            if (! $user->isSuperAdmin()) {
                // Non-SuperAdmins can only create custom roles for their tenant
                if (! $user->tenant_id) {
                    return response()->json([
                        'message' => 'You must belong to a tenant to create roles',
                    ], 403);
                }

                $data['tenant_id'] = $user->tenant_id;
                $data['is_custom'] = true;
                $data['role_type'] = Role::ROLE_TYPE_TENANT;
                $data['role_classification'] = Role::CLASSIFICATION_CUSTOM;
            } else {
                $tenantId = $validated['tenant_id'] ?? null;
                $isCustom = (bool) ($validated['is_custom'] ?? false);
                $data['tenant_id'] = $tenantId;
                $data['is_custom'] = $isCustom;
                $data['role_type'] = empty($tenantId)
                    ? Role::ROLE_TYPE_PLATFORM
                    : Role::ROLE_TYPE_TENANT;
                $data['role_classification'] = $isCustom
                    ? Role::CLASSIFICATION_CUSTOM
                    : (empty($tenantId)
                        ? Role::CLASSIFICATION_PROTECTED_SYSTEM
                        : Role::CLASSIFICATION_DEFAULT_TEMPLATE);
            }

            $existingRole = Role::where('name', $data['name'])
                ->where('tenant_id', $data['tenant_id'] ?? null)
                ->exists();

            if ($existingRole) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['name' => ['Role name must be unique within the tenant.']],
                ], 422);
            }

            $role = Role::create($data);

            // AUDIT: Log role creation
            $this->auditService->logRoleCreated($role, auth()->user());

            Log::info('Role created', ['role_id' => $role->id, 'created_by' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => [
                    'role' => $role,
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error creating role',
            ], 500);
        }
    }

    /**
     * Tenant endpoint: create tenant-scoped role.
     */
    public function tenantStore(StoreTenantRoleRequest $request): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = $this->tenantRoleService->createTenantRole($request->user(), $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tenant role created successfully',
                'data' => ['role' => $role],
            ], 201);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating tenant role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating tenant role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing role.
     * - SuperAdmin can update any role
     * - EkklesiaAdmin can update only custom roles for their tenant
     * - System roles (SuperAdmin, EkklesiaAdmin, etc.) cannot be modified
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            // Tenant-context path uses dedicated service guardrails.
            if (
                $user->tenant_id &&
                ! $user->isSuperAdmin() &&
                ! $user->isEkklesiaAdmin() &&
                ! $user->isEkklesiaManager()
            ) {
                $validator = Validator::make($request->all(), [
                    'name' => [
                        'sometimes',
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('roles', 'name')
                            ->where(function ($query) use ($user) {
                                return $query->where('tenant_id', $user->tenant_id);
                            })
                            ->ignore($id),
                    ],
                    'description' => 'nullable|string',
                    'level' => 'sometimes|required|integer|min:1|max:10',
                    'active' => 'nullable|boolean',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $role = $this->tenantRoleService->updateTenantRole($user, $role, $validator->validated());

                return response()->json([
                    'success' => true,
                    'message' => 'Role updated successfully',
                    'data' => [
                        'role' => $role,
                    ],
                ]);
            }

            // Check if role can be modified
            if ($role->isGlobal() && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can modify global roles',
                ], 403);
            }

            if ($role->tenant_id && $role->tenant_id !== $user->tenant_id && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You can only modify roles for your tenant',
                ], 403);
            }

            // Prevent modification of system roles
            if (! $role->isCustom()) {
                return response()->json([
                    'message' => 'System roles cannot be modified. Create a custom role instead.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('roles', 'name')
                        ->where(function ($query) use ($role) {
                            return $query->where('tenant_id', $role->tenant_id);
                        })
                        ->ignore($id),
                ],
                'description' => 'nullable|string',
                'level' => 'sometimes|required|integer|min:1|max:10',
                'active' => 'nullable|boolean',
            ]);

            // SECURITY: Prevent level modification for system roles (even if somehow passed validation)
            // Level changes can break role hierarchy and permission inheritance
            if ($request->has('level') && ! $role->isCustom()) {
                return response()->json([
                    'message' => 'Cannot modify level of system roles',
                ], 403);
            }

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $role->update($request->only(['name', 'description', 'level', 'active']));

            // PERFORMANCE: Clear permission cache for all users with this role
            // This ensures users get updated permissions immediately
            $role->clearUsersPermissionCache();

            Log::info('Role updated', ['role_id' => $role->id, 'updated_by' => auth()->id()]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => [
                    'role' => $role,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error updating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: update tenant role with protected-role guardrails.
     */
    public function tenantUpdate(UpdateTenantRoleRequest $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);
            $role = $this->tenantRoleService->updateTenantRole($request->user(), $role, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tenant role updated successfully',
                'data' => ['role' => $role],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating tenant role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating tenant role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a role (soft delete).
     * - Only custom roles can be deleted
     * - System roles are protected
     */
    public function destroy($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            // Tenant-context path uses dedicated service guardrails.
            if (
                $user->tenant_id &&
                ! $user->isSuperAdmin() &&
                ! $user->isEkklesiaAdmin() &&
                ! $user->isEkklesiaManager()
            ) {
                $this->tenantRoleService->deleteTenantRole($user, $role);

                return response()->json([
                    'message' => 'Role deleted successfully',
                ]);
            }

            // Prevent deletion of system roles
            if (! $role->isCustom()) {
                return response()->json([
                    'message' => 'System roles cannot be deleted',
                ], 403);
            }

            // Check authorization
            if (! $user->isSuperAdmin() && $role->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'message' => 'You can only delete roles for your tenant',
                ], 403);
            }

            // Check if role has users
            if ($role->assignedUsersCount() > 0) {
                return response()->json([
                    'message' => 'Cannot delete role with assigned users. Please reassign users first.',
                ], 422);
            }

            // PERFORMANCE: Clear permission cache for all users with this role before deletion
            $role->clearUsersPermissionCache();

            // AUDIT: Log role deletion (before deletion so we have the data)
            $this->auditService->logRoleDeleted($role, auth()->user());

            $role->delete();

            Log::warning('Role deleted', ['role_id' => $id, 'deleted_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role deleted successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error deleting role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: delete tenant custom role with protected-role guardrails.
     */
    public function tenantDestroy($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);
            $this->tenantRoleService->deleteTenantRole(auth()->user(), $role);

            return response()->json([
                'success' => true,
                'message' => 'Tenant role deleted successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting tenant role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error deleting tenant role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: activate tenant role with protected-role guardrails.
     */
    public function tenantActivate($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);
            $role = $this->tenantRoleService->activateTenantRole(auth()->user(), $role);

            return response()->json([
                'success' => true,
                'message' => 'Tenant role activated successfully',
                'data' => ['role' => $role],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error activating tenant role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error activating tenant role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: deactivate tenant role with protected-role guardrails.
     */
    public function tenantDeactivate($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $role = Role::findOrFail($id);
            $role = $this->tenantRoleService->deactivateTenantRole(auth()->user(), $role);

            return response()->json([
                'success' => true,
                'message' => 'Tenant role deactivated successfully',
                'data' => ['role' => $role],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deactivating tenant role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error deactivating tenant role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted role.
     */
    public function restore($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::withTrashed()->findOrFail($id);

            if (! $this->canMutateLegacyRole($user, $role)) {
                return response()->json([
                    'message' => 'You can only restore roles for your tenant',
                ], 403);
            }

            if ($role->isProtectedSystemRole()) {
                return response()->json([
                    'message' => 'Protected system roles cannot be restored via this endpoint',
                ], 422);
            }

            $role->restore();

            Log::info('Role restored', ['role_id' => $id, 'restored_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role restored successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error restoring role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error restoring role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate a role.
     */
    public function activate($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            if (! $this->canMutateLegacyRole($user, $role)) {
                return response()->json([
                    'message' => 'You can only activate roles for your tenant',
                ], 403);
            }

            if ($role->isProtectedSystemRole()) {
                return response()->json([
                    'message' => 'Protected system roles cannot be activated or deactivated',
                ], 422);
            }

            // Only custom roles can be activated/deactivated
            if (! $role->isCustom() && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can activate/deactivate system roles',
                ], 403);
            }

            $role->activate();

            Log::info('Role activated', ['role_id' => $id, 'activated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role activated successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error activating role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error activating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate a role.
     */
    public function deactivate($id): JsonResponse
    {
        try {
            if (! $this->canManageRoles()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $role = Role::findOrFail($id);

            if (! $this->canMutateLegacyRole($user, $role)) {
                return response()->json([
                    'message' => 'You can only deactivate roles for your tenant',
                ], 403);
            }

            if ($role->isProtectedSystemRole()) {
                return response()->json([
                    'message' => 'Protected system roles cannot be activated or deactivated',
                ], 422);
            }

            // Only custom roles can be activated/deactivated
            if (! $role->isCustom() && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can activate/deactivate system roles',
                ], 403);
            }

            $role->deactivate();

            Log::info('Role deactivated', ['role_id' => $id, 'deactivated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Role deactivated successfully',
                'role' => $role,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deactivating role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error deactivating role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if current user can manage roles.
     */
    /**
     * SECURITY: Check if user can manage roles (create, update, delete)
     * - SuperAdmin: Can manage all roles
     * - EkklesiaAdmin/Manager: Can manage all roles
     * - Tenant Administrator: Can manage roles within their tenant only
     * - Regular Tenant Users: Cannot manage roles
     */
    private function canManageRoles(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // SuperAdmin, EkklesiaAdmin, and EkklesiaManager can manage ALL roles
        if ($user->isSuperAdmin() || $user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return true;
        }

        // Tenant Administrators can manage roles within their tenant
        if ($user->isTenantAdmin()) {
            return true;
        }

        // All other users cannot manage roles
        return false;
    }

    /**
     * Tenant-scoped actors may only mutate roles owned by their tenant.
     * Platform actors retain broader access subject to other guards.
     */
    private function canMutateLegacyRole(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return is_null($role->tenant_id)
                || ($user->tenant_id && $role->tenant_id === $user->tenant_id);
        }

        if ($user->tenant_id) {
            return $role->tenant_id === $user->tenant_id;
        }

        return false;
    }

    /**
     * Check if current user can view a specific role.
     */
    /**
     * SECURITY: Check if user can view a specific role
     * - SuperAdmin: Can view all roles
     * - EkklesiaAdmin/Manager: Can view global + all tenant roles
     * - Tenant Users: Can ONLY view their tenant's roles
     */
    private function canViewRole(Role $role): bool
    {
        $user = auth()->user();

        // SuperAdmin can view all roles
        if ($user->isSuperAdmin()) {
            return true;
        }

        // SECURITY FIX: System-level Ekklesia roles can view:
        // 1. Global roles (tenant_id = null)
        // 2. Roles from their own tenant (if they have tenant_id)
        // NOT all tenant roles
        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            // If role is global, allow access
            if (is_null($role->tenant_id)) {
                return true;
            }
            // If role belongs to user's tenant, allow access
            if ($user->tenant_id && $role->tenant_id === $user->tenant_id) {
                return true;
            }

            // Otherwise, deny access
            return false;
        }

        // CRITICAL SECURITY: Tenant users can ONLY view their own tenant's roles
        // They CANNOT view global roles or other tenants' roles
        if ($user->tenant_id) {
            $canView = $role->tenant_id === $user->tenant_id;

            if (! $canView) {
                Log::warning('Tenant user attempted to view unauthorized role', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_tenant_id' => $user->tenant_id,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'role_tenant_id' => $role->tenant_id,
                ]);
            }

            return $canView;
        }

        // Users without tenant are denied (shouldn't happen in normal operation)
        Log::warning('User without tenant attempted to view role', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return false;
    }
}
