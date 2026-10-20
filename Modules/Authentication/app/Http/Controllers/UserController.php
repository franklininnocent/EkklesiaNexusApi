<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\TokenService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Http\Requests\StoreUserRequest;
use Modules\Authentication\Http\Requests\SyncTenantUserRolesRequest;
use Modules\Authentication\Http\Requests\UpdateUserRequest;
use Modules\Authentication\Http\Requests\UploadUserProfileImageRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordAuthorizationService;
use Modules\Authentication\Services\PasswordManagementService;
use Modules\Authentication\Services\UserPersonLinkService;
use Modules\Authentication\Services\UserProfileImageService;
use Modules\RolesAndPermissions\Services\TenantRoleAssignmentService;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Services\Media\ImageMediaException;

/**
 * UserController - Tenant User Management
 *
 * This controller handles user management operations within a tenant.
 * Tenant administrators can create, update, delete, and manage users
 * with multiple role assignments.
 *
 * Features:
 * - Multi-role user management
 * - Tenant isolation (users can only manage users within their tenant)
 * - Permission aggregation from multiple roles
 * - Secure password hashing
 * - Audit logging
 */
class UserController extends Controller
{
    public function __construct(
        private TenantRoleAssignmentService $tenantRoleAssignmentService,
        private UserProfileImageService $userProfileImageService,
        private UserPersonLinkService $userPersonLinkService,
        private PasswordAuthorizationService $passwordAuthorization,
        private PasswordManagementService $passwordManagement,
        private TokenService $tokenService,
    ) {}

    /**
     * Display a listing of users for the authenticated user's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (! $user->relationLoaded('roles')) {
                $user->load('roles');
            }

            // Authorization: Users with users.view permission OR tenant administrators can view users
            $hasPermission = $user->hasPermission('users.view');
            $isTenantAdmin = $user->isTenantAdmin();
            $isSuperAdmin = $user->isSuperAdmin();
            $isEkklesiaAdmin = $user->isEkklesiaAdmin();

            $canView = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;

            // Log authorization check for debugging
            Log::debug('User list authorization check', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'has_permission' => $hasPermission,
                'is_tenant_admin' => $isTenantAdmin,
                'is_super_admin' => $isSuperAdmin,
                'is_ekklesia_admin' => $isEkklesiaAdmin,
                'can_view' => $canView,
                'roles' => $user->roles->pluck('name')->toArray(),
            ]);

            if (! $canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view users.',
                ], 403);
            }

            // Build query with tenant isolation
            $query = User::with(['roles', 'tenant'])
                ->where('tenant_id', $user->tenant_id);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%")
                        ->orWhere('contact_number', 'ilike', "%{$search}%");
                });
            }

            // Filter by role
            if ($request->filled('role_id')) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('roles.id', $request->input('role_id'));
                });
            }

            // Filter by status
            if ($request->filled('status')) {
                $status = $request->input('status');
                if ($status === 'active') {
                    $query->where('active', 1);
                } elseif ($status === 'inactive') {
                    $query->where('active', 0);
                }
            }

            // Pagination
            $perPage = $request->input('per_page', 15);
            if ($perPage === 'all') {
                $users = $query->get();

                // Add authorization flags to each user
                $usersWithAuth = $users->map(function ($targetUser) use ($user) {
                    $userData = $targetUser->toArray();
                    $userData['can_edit'] = $user->canEditUser($targetUser);
                    $userData['can_reset_password'] = $this->passwordAuthorization->canResetPassword($user, $targetUser);
                    $userData['is_self'] = $user->id === $targetUser->id;

                    return $userData;
                });

                return response()->json([
                    'success' => true,
                    'data' => $usersWithAuth,
                    'meta' => [
                        'total' => $users->count(),
                    ],
                ]);
            }

            $users = $query->paginate($perPage);

            // Add authorization flags to each user
            $usersWithAuth = collect($users->items())->map(function ($targetUser) use ($user) {
                $userData = $targetUser->toArray();
                $userData['can_edit'] = $user->canEditUser($targetUser);
                $userData['can_reset_password'] = $this->passwordAuthorization->canResetPassword($user, $targetUser);
                $userData['is_self'] = $user->id === $targetUser->id;

                return $userData;
            });

            return response()->json([
                'success' => true,
                'data' => $usersWithAuth,
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching users: '.$e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching users.',
            ], 500);
        }
    }

    /**
     * Display the specified user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Authorization check: Users with users.view permission OR tenant administrators can view users
            $canView = $authUser->hasPermission('users.view') ||
                      $authUser->isTenantAdmin() ||
                      $authUser->isSuperAdmin() ||
                      $authUser->isEkklesiaAdmin();

            if (! $canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view users.',
                ], 403);
            }

            // SuperAdmin/EkklesiaAdmin can view any user, others only their tenant
            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::with(['roles.permissions', 'tenant', 'addresses'])->findOrFail($id);
            } else {
                $user = User::with(['roles.permissions', 'tenant', 'addresses'])
                    ->where('tenant_id', $authUser->tenant_id)
                    ->findOrFail($id);
            }

            // Include aggregated permissions
            $allPermissions = $user->getAllPermissions();

            // Check if current user can edit this user
            $canEdit = $authUser->canEditUser($user);
            $isSelf = $authUser->id === $user->id;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'all_permissions' => $allPermissions,
                    'can_edit' => $canEdit,
                    'can_reset_password' => $this->passwordAuthorization->canResetPassword($authUser, $user),
                    'is_self' => $isSelf,
                    'edit_restriction_reason' => ! $canEdit ? $this->getEditRestrictionReason($authUser, $user) : null,
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching user details: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user details.',
            ], 500);
        }
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $authUser = $request->user();

            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (! $authUser->relationLoaded('roles')) {
                $authUser->load('roles');
            }

            // Authorization: Users with users.create permission OR tenant administrators can create users
            // Tenant administrators should be able to manage users within their tenant
            $hasPermission = $authUser->hasPermission('users.create');
            $isTenantAdmin = $authUser->isTenantAdmin();
            $isSuperAdmin = $authUser->isSuperAdmin();
            $isEkklesiaAdmin = $authUser->isEkklesiaAdmin();

            $canCreate = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;

            // Log authorization check for debugging
            Log::debug('User creation authorization check', [
                'user_id' => $authUser->id,
                'user_email' => $authUser->email,
                'tenant_id' => $authUser->tenant_id,
                'has_permission' => $hasPermission,
                'is_tenant_admin' => $isTenantAdmin,
                'is_super_admin' => $isSuperAdmin,
                'is_ekklesia_admin' => $isEkklesiaAdmin,
                'can_create' => $canCreate,
                'roles' => $authUser->roles->pluck('name')->toArray(),
            ]);

            if (! $canCreate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to create users.',
                ], 403);
            }

            $linkedPerson = null;
            if ($request->filled('person_id')) {
                $linkedPerson = $this->userPersonLinkService->resolvePersonLink(
                    (int) $authUser->tenant_id,
                    (string) $request->input('person_id')
                );
            }

            $roleIds = $request->input('role_ids', []);
            if ($authUser->tenant_id && Role::hasNonTenantAssignableRoles($roleIds, (int) $authUser->tenant_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more selected roles do not belong to your tenant.',
                ], 422);
            }

            if ($authUser->tenant_id && (bool) $request->input('active', 1) && app()->bound(TenantLimitGuard::class)) {
                app(TenantLimitGuard::class)->assertTenantCanAdd((int) $authUser->tenant_id, 'STAFF_USER_LIMIT', 1);
            }

            // Create the user
            $user = new User([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'contact_number' => $request->input('contact_number'),
                'tenant_id' => $authUser->tenant_id,
                'user_type' => $request->input('user_type', User::USER_TYPE_PRIMARY_CONTACT),
                'active' => $request->input('active', 1),
            ]);

            $this->passwordManagement->setInitialPassword($user, (string) $request->input('password'), true);
            $user->save();
            $this->passwordManagement->recordPasswordHistory($user);

            // Assign roles
            if (! empty($roleIds)) {
                $user->syncRoles($roleIds);
            }

            if ($linkedPerson !== null) {
                $this->userPersonLinkService->attach($user, $linkedPerson);
            }

            // Load relationships for response
            $user->load(['roles', 'tenant', 'person']);

            DB::commit();

            Log::info('User created successfully', [
                'created_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
                'roles' => $roleIds,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully.',
                'data' => $user,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            Log::error('Error creating user: '.$e->getMessage(), [
                'created_by' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user.',
            ], 500);
        }
    }

    /**
     * Update the specified user in storage.
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Authorization check: Users with users.update permission OR tenant administrators can update users
            $canUpdate = $authUser->hasPermission('users.update') ||
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();

            if (! $canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            // Find user - SuperAdmin/EkklesiaAdmin can access any user, others only their tenant
            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);
            }

            // Use comprehensive authorization check
            if (! $authUser->canEditUser($user)) {
                // Determine specific error message based on the restriction
                if ($authUser->id === $user->id) {
                    $message = 'You cannot edit your own account details. Please contact your administrator for assistance.';
                } elseif ($user->is_primary_admin && ! $authUser->isSuperAdmin() && ! $authUser->isEkklesiaAdmin()) {
                    $message = 'Only Super Admin and Ekklesia Admin can modify the primary administrator account.';
                } elseif (! $authUser->is_primary_admin && $user->isTenantAdmin()) {
                    $message = 'Secondary administrators cannot modify other administrator accounts. Please contact the primary administrator.';
                } elseif ($authUser->tenant_id !== $user->tenant_id) {
                    $message = 'You can only modify users within your own tenant.';
                } else {
                    $message = 'You do not have permission to modify this user account.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            // Prevent deactivation of primary admin (additional safety check)
            if ($user->is_primary_admin && $request->input('active') == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deactivated. This account is essential for maintaining tenant administrative continuity.',
                ], 403);
            }

            $linkedPerson = null;
            $shouldDetachPerson = false;
            if ($request->has('person_id')) {
                $personId = $request->input('person_id');
                if ($personId === null || $personId === '') {
                    $shouldDetachPerson = true;
                } else {
                    $linkedPerson = $this->userPersonLinkService->resolvePersonLink(
                        (int) $authUser->tenant_id,
                        (string) $personId,
                        $user->id
                    );
                }
            }

            DB::beginTransaction();

            $this->assertReactivationWithinStaffLimit($authUser, $user, $request->input('active', $user->active));

            // Update basic info
            $user->name = $request->input('name', $user->name);
            $user->email = $request->input('email', $user->email);
            $user->contact_number = $request->input('contact_number', $user->contact_number);
            $user->active = $request->input('active', $user->active);

            $user->save();

            // Update roles if provided
            if ($request->has('role_ids')) {
                $roleIds = $request->input('role_ids', []);

                if ($authUser->tenant_id && Role::hasNonTenantAssignableRoles($roleIds, (int) $authUser->tenant_id)) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected roles do not belong to your tenant.',
                    ], 422);
                }

                $user->syncRoles($roleIds);
            }

            if ($shouldDetachPerson) {
                $this->userPersonLinkService->detach($user);
            } elseif ($linkedPerson !== null) {
                $this->userPersonLinkService->attach($user, $linkedPerson);
            }

            // Load relationships for response
            $user->load(['roles', 'tenant', 'person']);

            DB::commit();

            Log::info('User updated successfully', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully.',
                'data' => $user,
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            Log::error('Error updating user: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user.',
            ], 500);
        }
    }

    /**
     * Remove the specified user from storage (soft delete).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $authUser = $request->user();

            // Authorization check: Users with users.delete permission OR tenant administrators can delete users
            $canDelete = $authUser->hasPermission('users.delete') ||
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();

            if (! $canDelete) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to delete users.',
                ], 403);
            }

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Prevent deletion of system accounts
            if ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete system administrator accounts.',
                ], 403);
            }

            // Prevent tenant users from deleting the primary admin account
            if ($user->is_primary_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deleted. This account is essential for maintaining tenant administrative continuity and system integrity.',
                ], 403);
            }

            // Prevent self-deletion
            if ($user->id === $authUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account.',
                ], 403);
            }

            if ($user->profile_image_path && $user->tenant_id) {
                $this->userProfileImageService->deleteProfileImage(
                    $user->profile_image_path,
                    (int) $user->tenant_id
                );
                $user->profile_image_path = null;
                $user->save();
            }

            $user->delete(); // Soft delete
            $this->tokenService->revokeAllTokens($user->id);

            DB::commit();

            Log::info('User deleted successfully', [
                'deleted_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully.',
            ]);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error deleting user: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the user.',
            ], 500);
        }
    }

    /**
     * Get all permissions for a specific user (aggregated from all roles).
     */
    public function permissions(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Authorization check: Users with users.view permission OR tenant administrators can view user permissions
            $canView = $authUser->hasPermission('users.view') ||
                      $authUser->isTenantAdmin() ||
                      $authUser->isSuperAdmin() ||
                      $authUser->isEkklesiaAdmin();

            if (! $canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user permissions.',
                ], 403);
            }

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Get all permissions
            $allPermissions = $user->getAllPermissions();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'roles' => $user->roles,
                    'permissions' => $allPermissions,
                    'total_permissions' => $allPermissions->count(),
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching user permissions: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user permissions.',
            ], 500);
        }
    }

    /**
     * Get roles for a specific user inside current tenant scope.
     */
    public function getRoles(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            $canView = $authUser->hasPermission('users.view') ||
                $authUser->isTenantAdmin() ||
                $authUser->isSuperAdmin() ||
                $authUser->isEkklesiaAdmin();

            if (! $canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user roles.',
                ], 403);
            }

            $user = $this->tenantRoleAssignmentService->getUserRoles($authUser, $id);

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->roles,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error fetching user roles: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user roles.',
            ], 500);
        }
    }

    /**
     * Assign roles to a user.
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Authorization check: Requires both users.update and roles.assign permissions OR tenant admin
            $canAssign = ($authUser->hasPermission('users.update') && $authUser->hasPermission('roles.assign')) ||
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();

            if (! $canAssign) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to assign roles.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'role_ids' => 'required|array|min:1',
                'role_ids.*' => 'required|integer|exists:roles,id',
            ]);

            $roleIds = $request->input('role_ids');
            $targetUser = User::findOrFail($id);

            if ($authUser->tenant_id) {
                // Tenant-context assignments must use strict tenant guardrails.
                $user = $this->tenantRoleAssignmentService->syncUserRoles($authUser, $targetUser, $roleIds);
            } else {
                // Platform-context fallback for super/ekklesia operators.
                $roles = Role::whereIn('id', $roleIds)
                    ->where('active', 1)
                    ->whereNull('deleted_at')
                    ->get();

                if ($roles->count() !== count($roleIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected roles are invalid.',
                    ], 422);
                }

                $targetUser->syncRoles($roleIds);
                $primaryRoleId = $roles->sortBy('level')->pluck('id')->first();
                if ($primaryRoleId) {
                    $targetUser->role_id = $primaryRoleId;
                    $targetUser->save();
                }
                $user = $targetUser->fresh('roles');
            }

            Log::info('Roles assigned to user', [
                'assigned_by' => $authUser->id,
                'user_id' => $user->id,
                'role_ids' => $roleIds,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Roles assigned successfully.',
                'data' => [
                    'user' => $user,
                    'roles' => $user->roles,
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error assigning roles: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while assigning roles.',
            ], 500);
        }
    }

    /**
     * Tenant endpoint: sync roles for a tenant user with guardrails.
     */
    public function syncTenantRoles(SyncTenantUserRolesRequest $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();
            $roleIds = $request->validated()['role_ids'];
            $user = User::findOrFail($id);
            $user = $this->tenantRoleAssignmentService->syncUserRoles($authUser, $user, $roleIds);

            return response()->json([
                'success' => true,
                'message' => 'User roles updated successfully.',
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->roles,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Exception $e) {
            Log::error('Error syncing tenant user roles: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating user roles.',
            ], 500);
        }
    }

    /**
     * Update user status (active/inactive).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $authUser = $request->user();

            // Authorization check: Users with users.update permission OR tenant administrators can update user status
            $canUpdate = $authUser->hasPermission('users.update') ||
                        $authUser->isTenantAdmin() ||
                        $authUser->isSuperAdmin() ||
                        $authUser->isEkklesiaAdmin();

            if (! $canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'active' => 'required|integer|in:0,1',
            ]);

            // Find user with tenant isolation
            $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);

            // Prevent deactivation of primary admin
            if ($user->is_primary_admin && $request->input('active') === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'The primary admin account cannot be deactivated. This account is essential for maintaining tenant administrative continuity.',
                ], 403);
            }

            // Prevent self-deactivation
            if ($user->id === $authUser->id && $request->input('active') === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'You cannot deactivate your own account.',
                ], 403);
            }

            $this->assertReactivationWithinStaffLimit($authUser, $user, $request->input('active'));

            // Update status
            $user->active = $request->input('active');
            $user->save();

            if ((int) $user->active !== 1) {
                $this->tokenService->revokeAllTokens($user->id);
            }

            DB::commit();

            Log::info('User status updated', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'new_status' => $user->active,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully.',
                'data' => $user,
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            Log::error('Error updating user status: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the user status.',
            ], 500);
        }
    }

    /**
     * Reactivating a staff login counts against the plan's staff user limit, like creating one.
     */
    private function assertReactivationWithinStaffLimit(User $authUser, User $user, mixed $requestedActive): void
    {
        if (! $authUser->tenant_id || ! $user->tenant_id || (int) $user->active === 1 || (int) $requestedActive !== 1) {
            return;
        }
        if (app()->bound(TenantLimitGuard::class)) {
            app(TenantLimitGuard::class)->assertTenantCanAdd((int) $user->tenant_id, 'STAFF_USER_LIMIT', 1);
        }
    }

    /**
     * Search active parish clergy leaders available for login linking.
     */
    public function linkableClergy(Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();

            $canSearch = $authUser->hasPermission('users.create')
                || $authUser->hasPermission('users.update')
                || $authUser->isTenantAdmin()
                || $authUser->isSuperAdmin()
                || $authUser->isEkklesiaAdmin();

            if (! $canSearch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to search parish leaders.',
                ], 403);
            }

            if (! $authUser->tenant_id) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $search = $request->input('search');
            $results = $this->userPersonLinkService->listLinkableClergy(
                (int) $authUser->tenant_id,
                is_string($search) ? $search : null
            );

            return response()->json([
                'success' => true,
                'data' => $results->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching linkable clergy: '.$e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while searching parish leaders.',
            ], 500);
        }
    }

    /**
     * Get user statistics for the current tenant.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $authUser = $request->user();

            // Load roles relationship if not already loaded (needed for isTenantAdmin check)
            if (! $authUser->relationLoaded('roles')) {
                $authUser->load('roles');
            }

            // Authorization: Users with users.view permission OR tenant administrators can view statistics
            $hasPermission = $authUser->hasPermission('users.view');
            $isTenantAdmin = $authUser->isTenantAdmin();
            $isSuperAdmin = $authUser->isSuperAdmin();
            $isEkklesiaAdmin = $authUser->isEkklesiaAdmin();

            $canView = $hasPermission || $isTenantAdmin || $isSuperAdmin || $isEkklesiaAdmin;

            if (! $canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to view user statistics.',
                ], 403);
            }

            // Get statistics for the user's tenant (tenant isolation)
            // Build base query for tenant
            $baseQuery = User::where('tenant_id', $authUser->tenant_id);

            // Get total count
            $total = (clone $baseQuery)->count();

            // Get active users count
            $active = (clone $baseQuery)->where('active', 1)->count();

            // Get inactive users count
            $inactive = (clone $baseQuery)->where('active', 0)->count();

            Log::debug('User statistics retrieved', [
                'user_id' => $authUser->id,
                'tenant_id' => $authUser->tenant_id,
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching user statistics: '.$e->getMessage(), [
                'user_id' => $request->user()->id ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching user statistics.',
            ], 500);
        }
    }

    /**
     * Get the reason why a user cannot edit another user.
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user being edited
     * @return string|null The restriction reason or null if can edit
     */
    private function getEditRestrictionReason(User $authUser, User $targetUser): ?string
    {
        if ($authUser->id === $targetUser->id) {
            return 'You cannot edit your own account details. Please contact your administrator for assistance.';
        }

        if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
            return null; // SuperAdmin and EkklesiaAdmin can edit anyone
        }

        if ($authUser->tenant_id !== $targetUser->tenant_id) {
            return 'You can only modify users within your own tenant.';
        }

        if ($targetUser->is_primary_admin) {
            return 'Only Super Admin and Ekklesia Admin can modify the primary administrator account.';
        }

        if (! $authUser->is_primary_admin && $targetUser->isTenantAdmin()) {
            return 'Secondary administrators cannot modify other administrator accounts. Please contact the primary administrator.';
        }

        if (! $authUser->hasPermission('users.update')) {
            return 'You do not have permission to modify user accounts.';
        }

        return null;
    }

    /**
     * Upload or replace a user's profile image.
     */
    public function uploadProfileImage(UploadUserProfileImageRequest $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            $canUpdate = $authUser->hasPermission('users.update')
                || $authUser->isTenantAdmin()
                || $authUser->isSuperAdmin()
                || $authUser->isEkklesiaAdmin();

            if (! $canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);
            }

            if (! $authUser->canEditUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->getEditRestrictionReason($authUser, $user)
                        ?? 'You do not have permission to modify this user account.',
                ], 403);
            }

            if (! $user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant.',
                ], 422);
            }

            $this->userProfileImageService->replaceProfileImage(
                $request->file('profile_image'),
                $user
            );

            $user->refresh();
            $user->load(['roles', 'tenant']);

            Log::info('User profile image updated', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile image uploaded successfully.',
                'data' => $user,
            ]);
        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error uploading user profile image: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while uploading the profile image.',
            ], 500);
        }
    }

    /**
     * Remove a user's profile image.
     */
    public function deleteProfileImage(Request $request, int $id): JsonResponse
    {
        try {
            $authUser = $request->user();

            $canUpdate = $authUser->hasPermission('users.update')
                || $authUser->isTenantAdmin()
                || $authUser->isSuperAdmin()
                || $authUser->isEkklesiaAdmin();

            if (! $canUpdate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to update users.',
                ], 403);
            }

            if ($authUser->isSuperAdmin() || $authUser->isEkklesiaAdmin()) {
                $user = User::findOrFail($id);
            } else {
                $user = User::where('tenant_id', $authUser->tenant_id)->findOrFail($id);
            }

            if (! $authUser->canEditUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => $this->getEditRestrictionReason($authUser, $user)
                        ?? 'You do not have permission to modify this user account.',
                ], 403);
            }

            if ($user->profile_image_path && $user->tenant_id) {
                $this->userProfileImageService->deleteProfileImage(
                    $user->profile_image_path,
                    (int) $user->tenant_id
                );
            }

            $user->profile_image_path = null;
            $user->save();
            $user->load(['roles', 'tenant']);

            Log::info('User profile image removed', [
                'updated_by' => $authUser->id,
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile image removed successfully.',
                'data' => $user,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or does not belong to your tenant.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting user profile image: '.$e->getMessage(), [
                'user_id' => $id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while removing the profile image.',
            ], 500);
        }
    }
}
