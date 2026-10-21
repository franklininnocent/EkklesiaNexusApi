<?php

namespace Modules\RolesAndPermissions\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordAuthorizationService;
use Modules\RolesAndPermissions\Http\Requests\SyncTenantRolePermissionsRequest;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Services\PermissionAuditService;
use Modules\RolesAndPermissions\Services\TenantPermissionCatalogService;
use Modules\RolesAndPermissions\Services\TenantPermissionCrudService;
use Modules\RolesAndPermissions\Services\TenantPermissionService;
use Modules\RolesAndPermissions\Support\RetiredProductPermissionCatalog;
use Modules\RolesAndPermissions\Traits\EnforcesTenantIsolation;

class PermissionsController extends Controller
{
    use EnforcesTenantIsolation;

    protected PermissionAuditService $auditService;

    protected TenantPermissionCatalogService $tenantPermissionCatalogService;

    protected TenantPermissionService $tenantPermissionService;

    protected TenantPermissionCrudService $tenantPermissionCrudService;

    protected PasswordAuthorizationService $passwordAuthorization;

    public function __construct(
        PermissionAuditService $auditService,
        TenantPermissionService $tenantPermissionService,
        TenantPermissionCatalogService $tenantPermissionCatalogService,
        TenantPermissionCrudService $tenantPermissionCrudService,
        PasswordAuthorizationService $passwordAuthorization,
    ) {
        $this->auditService = $auditService;
        $this->tenantPermissionService = $tenantPermissionService;
        $this->tenantPermissionCatalogService = $tenantPermissionCatalogService;
        $this->tenantPermissionCrudService = $tenantPermissionCrudService;
        $this->passwordAuthorization = $passwordAuthorization;
    }

    /**
     * List all permissions (with pagination and filters).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (! auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user = auth()->user();

            // List only — tenant custom permission creation belongs on POST /permissions (store).
            $query = RetiredProductPermissionCatalog::applyExclusion(Permission::query());

            $scopeDenied = $this->applyPermissionListScope($query, $user);
            if ($scopeDenied !== null) {
                return $scopeDenied;
            }

            // Apply filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('tenant_id') && $user->isSuperAdmin()) {
                $query->where('tenant_id', $request->tenant_id);
            }

            if ($request->has('module')) {
                $query->byModule($request->module);
            }

            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            if ($request->has('is_custom')) {
                $query->where('is_custom', $request->is_custom);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 15);

            // Handle 'all' case to return all records without pagination
            if ($perPage === 'all') {
                $permissions = $query->with('tenant')
                    ->orderBy('module')
                    ->orderBy('category')
                    ->orderBy('name')
                    ->get();

                return response()->json([
                    'success' => true,
                    'data' => $permissions,
                    'total' => $permissions->count(),
                ]);
            }

            $permissions = $query->with('tenant')
                ->orderBy('module')
                ->orderBy('category')
                ->orderBy('name')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $permissions->items(),
                'total' => $permissions->total(),
                'pagination' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching permissions: '.$e->getMessage());

            return response()->json([
                'message' => 'Error fetching permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: list tenant-assignable permissions.
     */
    public function tenantIndex(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $grouped = (bool) $request->boolean('grouped', true);
            $permissions = $this->tenantPermissionCatalogService->listForTenant($user, $grouped);

            return response()->json([
                'success' => true,
                'data' => $permissions,
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenant permission catalog: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching tenant permission catalog',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific permission.
     */
    public function show($id): JsonResponse
    {
        try {
            if (! auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $permission = Permission::with(['tenant', 'roles', 'users'])->findOrFail($id);

            if (! $this->canViewPermission($permission)) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json([
                'permission' => $permission,
                'stats' => [
                    'assigned_to_roles' => $permission->roles()->count(),
                    'assigned_to_users' => $permission->users()->count(),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching permission: '.$e->getMessage());

            return response()->json([
                'message' => 'Permission not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create a new permission.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:permissions,name',
                'display_name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'module' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
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

            // Tenant-context path uses dedicated service guardrails.
            if ($this->isTenantScopedActor($user)) {
                $permission = $this->tenantPermissionCrudService->createTenantPermission($user, [
                    'name' => $validated['name'],
                    'display_name' => $validated['display_name'],
                    'description' => $validated['description'] ?? null,
                    'module' => $validated['module'] ?? null,
                    'category' => $validated['category'] ?? null,
                ]);

                return response()->json([
                    'message' => 'Permission created successfully',
                    'permission' => $permission,
                ], 201);
            }

            if (! $user->isSuperAdmin() && ! $user->tenant_id) {
                return response()->json([
                    'message' => 'You must belong to a tenant to create permissions',
                ], 403);
            }

            $permission = Permission::create([
                'name' => $validated['name'],
                'display_name' => $validated['display_name'],
                'description' => $validated['description'] ?? null,
                'module' => $validated['module'] ?? null,
                'category' => $validated['category'] ?? null,
                'tenant_id' => $user->isSuperAdmin() ? ($validated['tenant_id'] ?? null) : $user->tenant_id,
                'is_custom' => $user->isSuperAdmin() ? (bool) ($validated['is_custom'] ?? false) : true,
                'active' => 1,
            ]);

            Log::info('Permission created', ['permission_id' => $permission->id, 'created_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission created successfully',
                'permission' => $permission,
            ], 201);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating permission: '.$e->getMessage());

            return response()->json([
                'message' => 'Error creating permission',
            ], 500);
        }
    }

    /**
     * Update an existing permission.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $permission = Permission::findOrFail($id);

            // Tenant-context path uses dedicated service guardrails.
            if (
                $user->tenant_id &&
                ! $user->isSuperAdmin() &&
                ! $user->isEkklesiaAdmin() &&
                ! $user->isEkklesiaManager()
            ) {
                $validator = Validator::make($request->all(), [
                    'name' => 'sometimes|required|string|max:255|unique:permissions,name,'.$id,
                    'display_name' => 'sometimes|required|string|max:255',
                    'description' => 'nullable|string',
                    'module' => 'nullable|string|max:255',
                    'category' => 'nullable|string|max:255',
                    'active' => 'nullable|boolean',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $permission = $this->tenantPermissionCrudService->updateTenantPermission($user, $permission, $validator->validated());

                return response()->json([
                    'message' => 'Permission updated successfully',
                    'permission' => $permission,
                ]);
            }

            // Check if permission can be modified
            if ($permission->isGlobal() && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'Only SuperAdmin can modify global permissions',
                ], 403);
            }

            if ($permission->tenant_id && $permission->tenant_id !== $user->tenant_id && ! $user->isSuperAdmin()) {
                return response()->json([
                    'message' => 'You can only modify permissions for your tenant',
                ], 403);
            }

            // Prevent modification of system permissions
            if (! $permission->isCustom()) {
                return response()->json([
                    'message' => 'System permissions cannot be modified. Create a custom permission instead.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255|unique:permissions,name,'.$id,
                'display_name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'module' => 'nullable|string|max:255',
                'category' => 'nullable|string|max:255',
                'active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission->update($request->only(['name', 'display_name', 'description', 'module', 'category', 'active']));

            Log::info('Permission updated', ['permission_id' => $permission->id, 'updated_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission updated successfully',
                'permission' => $permission,
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating permission: '.$e->getMessage());

            return response()->json([
                'message' => 'Error updating permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a permission (soft delete).
     */
    public function destroy($id): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = auth()->user();
            $permission = Permission::findOrFail($id);

            // Tenant-context path uses dedicated service guardrails.
            if (
                $user->tenant_id &&
                ! $user->isSuperAdmin() &&
                ! $user->isEkklesiaAdmin() &&
                ! $user->isEkklesiaManager()
            ) {
                $this->tenantPermissionCrudService->deleteTenantPermission($user, $permission);

                return response()->json([
                    'message' => 'Permission deleted successfully',
                ]);
            }

            // Prevent deletion of system permissions
            if (! $permission->isCustom()) {
                return response()->json([
                    'message' => 'System permissions cannot be deleted',
                ], 403);
            }

            // Check authorization
            if (! $user->isSuperAdmin() && $permission->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'message' => 'You can only delete permissions for your tenant',
                ], 403);
            }

            $permission->delete();

            Log::warning('Permission deleted', ['permission_id' => $id, 'deleted_by' => auth()->id()]);

            return response()->json([
                'message' => 'Permission deleted successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting permission: '.$e->getMessage());

            return response()->json([
                'message' => 'Error deleting permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign permission to a role.
     */
    public function assignToRole(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'role_id' => 'required|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $role = Role::findOrFail($request->role_id);
            $currentUser = auth()->user();

            // Tenant actors must use the tenant sync path (escalation + ownership checks).
            if ($this->isTenantScopedActor($currentUser)) {
                $nextPermissionIds = $role->permissions()
                    ->pluck('permissions.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! in_array((int) $permission->id, $nextPermissionIds, true)) {
                    $nextPermissionIds[] = (int) $permission->id;
                }

                $count = $this->tenantPermissionService->syncRolePermissions($currentUser, $role, $nextPermissionIds);

                return response()->json([
                    'success' => true,
                    'message' => 'Permission assigned to role successfully',
                    'data' => [
                        'role_id' => $role->id,
                        'permissions_count' => $count,
                    ],
                ]);
            }

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && ! $currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to assign restricted module permission to role (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'role_id' => $role->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                ], 403);
            }

            $passwordError = $this->validatePlatformPasswordRoleMutation($currentUser, $role, $permission, true);
            if ($passwordError !== null) {
                return $passwordError;
            }

            // SECURITY: Validate tenant isolation - permission and role must belong to same tenant
            // System permissions (tenant_id = null) can be assigned to any role
            // Tenant-specific permissions can only be assigned to roles from the same tenant
            if (! is_null($permission->tenant_id)) {
                if (is_null($role->tenant_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign tenant-specific permission to global role',
                    ], 422);
                }
                if ($permission->tenant_id !== $role->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permission from different tenant to role',
                    ], 422);
                }
            }

            $permission->assignToRole($role);

            // AUDIT: Log permission assignment
            $this->auditService->logPermissionAssignedToRole($permission, $role, auth()->user());

            Log::info('Permission assigned to role', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission assigned to role successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error assigning permission to role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error assigning permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove permission from a role.
     */
    public function removeFromRole(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'role_id' => 'required|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $role = Role::findOrFail($request->role_id);
            $currentUser = auth()->user();

            // Tenant actors must use the tenant sync path (escalation + ownership checks).
            if ($this->isTenantScopedActor($currentUser)) {
                $nextPermissionIds = $role->permissions()
                    ->pluck('permissions.id')
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn (int $id) => $id === (int) $permission->id)
                    ->values()
                    ->all();

                $count = $this->tenantPermissionService->syncRolePermissions($currentUser, $role, $nextPermissionIds);

                return response()->json([
                    'success' => true,
                    'message' => 'Permission removed from role successfully',
                    'data' => [
                        'role_id' => $role->id,
                        'permissions_count' => $count,
                    ],
                ]);
            }

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from removing "Tenants" and "Pope" module permissions
            // This ensures that even if a role has restricted permissions, non-SuperAdmin users cannot modify them
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && ! $currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to remove restricted module permission from role (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'role_id' => $role->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be managed by Super Administrators',
                ], 403);
            }

            $passwordError = $this->validatePlatformPasswordRoleMutation($currentUser, $role, $permission, false);
            if ($passwordError !== null) {
                return $passwordError;
            }

            $permission->removeFromRole($role);

            // AUDIT: Log permission removal from role
            $this->auditService->logPermissionRemovedFromRole($permission, $role, auth()->user());

            Log::info('Permission removed from role', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'removed_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission removed from role successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error removing permission from role: '.$e->getMessage());

            return response()->json([
                'message' => 'Error removing permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign permission directly to a user.
     * CRITICAL SECURITY: Prevents non-SuperAdmin users from assigning "Tenants" module permissions
     */
    public function assignToUser(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $user = User::findOrFail($request->user_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && ! $currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to assign restricted module permission to user (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'target_user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                ], 403);
            }

            // Tenant actors may only manage users in their own tenant.
            if ($this->isTenantScopedActor($currentUser)) {
                if ($user->tenant_id !== $currentUser->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permissions to a user from another tenant',
                    ], 403);
                }
                if (! is_null($permission->tenant_id) && $permission->tenant_id !== $currentUser->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permission from another tenant',
                    ], 403);
                }
                if (! $permission->isTenantAssignable()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Permission is not assignable in tenant context',
                    ], 403);
                }
                if (! $currentUser->isSuperAdmin() && ! $currentUser->hasPermission($permission->name)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Permission escalation blocked. You cannot assign '{$permission->name}'.",
                    ], 403);
                }
            }

            if (! $this->passwordAuthorization->canGrantPasswordPermissionToUser($currentUser, $user, $permission->name)) {
                return response()->json([
                    'success' => false,
                    'message' => "Permission escalation blocked. You cannot assign '{$permission->name}'.",
                ], 403);
            }

            // SECURITY: Validate tenant isolation - permission and user must belong to same tenant
            // System permissions (tenant_id = null) can be assigned to any user
            // Tenant-specific permissions can only be assigned to users from the same tenant
            if (! is_null($permission->tenant_id)) {
                if (is_null($user->tenant_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign tenant-specific permission to user without tenant',
                    ], 422);
                }
                if ($permission->tenant_id !== $user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot assign permission from different tenant to user',
                    ], 422);
                }
            }

            $permission->assignToUser($user);

            // AUDIT: Log permission assignment to user
            $this->auditService->logPermissionAssignedToUser($permission, $user, auth()->user());

            Log::info('Permission assigned to user', [
                'permission_id' => $permission->id,
                'user_id' => $user->id,
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission assigned to user successfully',
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error assigning permission to user: '.$e->getMessage());

            return response()->json([
                'message' => 'Error assigning permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove permission from a user.
     */
    public function removeFromUser(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'permission_id' => 'required|exists:permissions,id',
                'user_id' => 'required|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $permission = Permission::findOrFail($request->permission_id);
            $user = User::findOrFail($request->user_id);
            $currentUser = auth()->user();

            // CRITICAL SECURITY: Prevent non-SuperAdmin users from removing "Tenants" and "Pope" module permissions
            // This ensures that even if a user has restricted permissions, non-SuperAdmin users cannot modify them
            if (($permission->module === 'Tenants' || $permission->module === 'Pope') && ! $currentUser->isSuperAdmin()) {
                Log::warning('Non-SuperAdmin user attempted to remove restricted module permission from user (SuperAdmin only)', [
                    'user_id' => $currentUser->id,
                    'user_email' => $currentUser->email,
                    'permission_id' => $permission->id,
                    'permission_name' => $permission->name,
                    'permission_module' => $permission->module,
                    'target_user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: Tenants and Pope module permissions can only be managed by Super Administrators',
                ], 403);
            }

            if ($this->isTenantScopedActor($currentUser) && $user->tenant_id !== $currentUser->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot modify permissions for a user from another tenant',
                ], 403);
            }

            // SECURITY: Validate tenant isolation - permission and user must belong to same tenant
            // System permissions (tenant_id = null) can be removed from any user
            // Tenant-specific permissions can only be removed from users from the same tenant
            if (! is_null($permission->tenant_id) && ! is_null($user->tenant_id)) {
                if ($permission->tenant_id !== $user->tenant_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot remove permission from different tenant from user',
                    ], 422);
                }
            }

            $permission->removeFromUser($user);

            // AUDIT: Log permission removal from user
            $this->auditService->logPermissionRemovedFromUser($permission, $user, auth()->user());

            Log::info('Permission removed from user', [
                'permission_id' => $permission->id,
                'user_id' => $user->id,
                'removed_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Permission removed from user successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error removing permission from user: '.$e->getMessage());

            return response()->json([
                'message' => 'Error removing permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk assign permissions to a role.
     * This replaces all existing permissions for the role with the new set.
     */
    public function bulkAssignToRole(Request $request): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'role_id' => 'required|exists:roles,id',
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'required|exists:permissions,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $role = Role::findOrFail($request->role_id);

            // Authorization: Check if user can modify this role
            $user = auth()->user();
            if ($this->isTenantScopedActor($user)) {
                $count = $this->tenantPermissionService->syncRolePermissions($user, $role, $request->permission_ids);

                return response()->json([
                    'success' => true,
                    'message' => 'Permissions assigned to role successfully',
                    'data' => [
                        'role_id' => $role->id,
                        'permissions_count' => $count,
                    ],
                ]);
            }

            if (! $user->isSuperAdmin()) {
                if ($role->isGlobal() || ($role->tenant_id && $role->tenant_id !== $user->tenant_id)) {
                    return response()->json([
                        'message' => 'Unauthorized to modify this role',
                    ], 403);
                }

                // CRITICAL SECURITY: Prevent non-SuperAdmin users from assigning "Tenants" and "Pope" module permissions
                $permissionIds = $request->permission_ids;
                $restrictedPermissionIds = Permission::whereIn('module', ['Tenants', 'Pope'])
                    ->whereIn('id', $permissionIds)
                    ->pluck('id')
                    ->toArray();

                if (! empty($restrictedPermissionIds)) {
                    $restrictedPermissions = Permission::whereIn('id', $restrictedPermissionIds)
                        ->get(['id', 'name', 'module']);

                    Log::warning('Non-SuperAdmin user attempted to assign restricted module permissions (SuperAdmin only)', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'role_id' => $role->id,
                        'restricted_permission_ids' => $restrictedPermissionIds,
                        'restricted_modules' => $restrictedPermissions->pluck('module')->unique()->toArray(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: Tenants and Pope module permissions can only be assigned by Super Administrators',
                    ], 403);
                }
            }

            // SECURITY: Validate tenant isolation for all permissions before assignment
            $permissionIds = $request->permission_ids;
            $permissions = Permission::whereIn('id', $permissionIds)->get();

            foreach ($permissions as $permission) {
                if (! $user->isSuperAdmin() && $permission->scope === Permission::SCOPE_PLATFORM) {
                    return response()->json([
                        'success' => false,
                        'message' => "Cannot assign platform-only permission '{$permission->name}' in tenant context",
                    ], 422);
                }

                // System permissions (tenant_id = null) can be assigned to any role
                // Tenant-specific permissions can only be assigned to roles from the same tenant
                if (! is_null($permission->tenant_id)) {
                    if (is_null($role->tenant_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot assign tenant-specific permission '{$permission->name}' to global role",
                        ], 422);
                    }
                    if ($permission->tenant_id !== $role->tenant_id) {
                        return response()->json([
                            'success' => false,
                            'message' => "Cannot assign permission '{$permission->name}' from different tenant to role",
                        ], 422);
                    }
                }
            }

            // PERFORMANCE & SECURITY: Wrap in transaction for atomicity
            \DB::transaction(function () use ($role, $permissionIds) {
                // Use Laravel's sync method to replace all permissions atomically
                $role->permissions()->sync($permissionIds);

                // Clear permission cache for all users with this role
                $role->clearUsersPermissionCache();
            });

            Log::info('Bulk permissions assigned to role', [
                'role_id' => $role->id,
                'permission_count' => count($permissionIds),
                'assigned_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permissions assigned to role successfully',
                'data' => [
                    'role_id' => $role->id,
                    'permissions_count' => count($request->permission_ids),
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error bulk assigning permissions to role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error assigning permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get permissions for a specific role.
     * CRITICAL SECURITY: Filters out "Tenants" module permissions for non-SuperAdmin users
     */
    public function getPermissionsForRole($roleId): JsonResponse
    {
        try {
            if (! auth()->check()) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $role = Role::with('permissions')->findOrFail($roleId);

            // Authorization check
            $user = auth()->user();
            if (! $user->isSuperAdmin()) {
                if ($role->isGlobal() || ($role->tenant_id && $role->tenant_id !== $user->tenant_id)) {
                    return response()->json(['message' => 'Unauthorized'], 403);
                }
            }

            // Get permissions for the role
            $permissions = $role->permissions->reject(
                fn ($permission) => RetiredProductPermissionCatalog::isRetired($permission)
            )->values();

            // CRITICAL SECURITY: Filter out "Tenants" and "Pope" module permissions for non-SuperAdmin users
            if (! $user->isSuperAdmin()) {
                $permissions = $permissions->filter(function ($permission) {
                    return $permission->module !== 'Tenants' && $permission->module !== 'Pope';
                })->values(); // Re-index array after filtering
            }

            return response()->json([
                'success' => true,
                'data' => $permissions,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching permissions for role: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Tenant endpoint: replace all permissions assigned to a role.
     */
    public function syncPermissionsForRole(SyncTenantRolePermissionsRequest $request, $roleId): JsonResponse
    {
        try {
            if (! $this->canManagePermissions()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $user = $request->user();
            $role = Role::findOrFail($roleId);
            $permissionIds = $request->validated()['permission_ids'];
            $count = $this->tenantPermissionService->syncRolePermissions($user, $role, $permissionIds);

            return response()->json([
                'success' => true,
                'message' => 'Role permissions updated successfully.',
                'data' => [
                    'role_id' => $role->id,
                    'permissions_count' => $count,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error syncing tenant role permissions: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error syncing role permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if current user can manage permissions.
     */
    /**
     * SECURITY: Check if user can manage permissions (create, update, delete)
     * - SuperAdmin: Can manage all permissions
     * - EkklesiaAdmin/Manager: Can manage all permissions
     * - Tenant Administrator: Can manage custom permissions within their tenant only
     * - Regular Tenant Users: Cannot manage permissions
     */
    private function canManagePermissions(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // SuperAdmin, EkklesiaAdmin, and EkklesiaManager can manage ALL permissions
        if ($user->isSuperAdmin() || $user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            return true;
        }

        // Tenant Administrators can manage custom permissions within their tenant
        if ($user->isTenantAdmin()) {
            return true;
        }

        // All other users cannot manage permissions
        return false;
    }

    private function isTenantScopedActor(User $user): bool
    {
        return (bool) $user->tenant_id
            && ! $user->isSuperAdmin()
            && ! $user->isEkklesiaAdmin()
            && ! $user->isEkklesiaManager();
    }

    /**
     * SECURITY: Check if user can view a specific permission
     * - SuperAdmin: Can view all permissions
     * - EkklesiaAdmin/Manager: Can view all permissions
     * - Tenant Users: Can view system permissions + their tenant's custom permissions
     */
    private function canViewPermission(Permission $permission): bool
    {
        $user = auth()->user();

        if ($this->canViewPermissionRecord($permission, $user)) {
            return true;
        }

        if ($user && ! $user->isSuperAdmin()) {
            Log::warning('User attempted to view unauthorized permission', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_tenant_id' => $user->tenant_id,
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'permission_tenant_id' => $permission->tenant_id,
                'permission_is_custom' => $permission->is_custom,
                'permission_module' => $permission->module,
            ]);

            return false;
        }

        return false;
    }

    private function validatePlatformPasswordRoleMutation(User $actor, Role $role, Permission $permission, bool $assigning): ?JsonResponse
    {
        if ($this->passwordAuthorization->isSystemRequiredPermission($permission->name) && ! $assigning) {
            return response()->json([
                'success' => false,
                'message' => 'Change Own Password is a required system security capability and cannot be removed.',
            ], 422);
        }

        if ($actor->isSuperAdmin()) {
            return null;
        }

        if (! $this->passwordAuthorization->canGrantPasswordPermission($actor, $role, $permission->name)) {
            return response()->json([
                'success' => false,
                'message' => "Permission escalation blocked. You cannot modify '{$permission->name}'.",
            ], 403);
        }

        if (! $actor->hasPermission($permission->name) && in_array($permission->name, [
            PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES,
            PasswordAuthorizationService::PERMISSION_TENANT_ADMIN_RESET,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => "Permission escalation blocked. You cannot modify '{$permission->name}'.",
            ], 403);
        }

        return null;
    }
}
