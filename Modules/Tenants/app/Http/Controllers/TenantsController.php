<?php

namespace Modules\Tenants\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use Modules\Tenants\Contracts\TenantPlanAssigner;
use Modules\Tenants\Http\Requests\StoreTenantRequest;
use Modules\Tenants\Http\Requests\UpdateTenantRequest;
use Modules\Tenants\Http\Requests\UploadChurchProfileLogoRequest;
use Modules\Tenants\Http\Requests\UpdateSubscriptionSettingsRequest;
use Modules\Tenants\Http\Requests\UpdateTenantSubscriptionAccessPolicyRequest;
use Modules\Tenants\Http\Requests\UploadTenantLogoRequest;
use Modules\Tenants\Http\Resources\TenantDetailsResource;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\SubscriptionDurationOption;
use Modules\Tenants\Models\SubscriptionPlan;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantStatusAudit;
use Modules\Tenants\Services\AddressService;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Modules\Tenants\Services\FileUploadService;
use Modules\Tenants\Services\Media\ImageMediaException;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Services\TenantDetailsService;
use Modules\Tenants\Support\TenantContext;

class TenantsController extends Controller
{
    private const TENANT_LIST_SORT_COLUMNS = [
        'name',
        'slug',
        'plan',
        'active',
        'created_at',
        'tenant_tier',
        'users_count',
        'active_users_count',
    ];

    protected $fileUploadService;

    protected $addressService;

    protected SubscriptionService $subscriptionService;

    protected TenantDetailsService $tenantDetailsService;

    public function __construct(
        FileUploadService $fileUploadService,
        AddressService $addressService,
        SubscriptionService $subscriptionService,
        TenantDetailsService $tenantDetailsService
    ) {
        $this->fileUploadService = $fileUploadService;
        $this->addressService = $addressService;
        $this->subscriptionService = $subscriptionService;
        $this->tenantDetailsService = $tenantDetailsService;
    }

    /**
     * List all tenants with pagination and filters.
     *
     * @route GET /api/tenant/list
     */
    public function list(Request $request): JsonResponse
    {
        try {
            // Check authorization
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage tenants.',
                ], 403);
            }

            $query = Tenant::query();

            $this->applyTenantListFilters($query, $request);
            $this->applyTenantListSorting($query, $request);

            // Eager load relationships and user counts for list density columns
            $query->with(['creator', 'updater', 'addresses', 'primaryContact.addresses', 'secondaryContact.addresses'])
                ->withCount([
                    'users as users_count',
                    'users as active_users_count' => fn ($q) => $q->where('active', 1),
                ]);

            // Pagination
            $perPage = $request->get('per_page', 15);

            if ($perPage === 'all') {
                $tenants = $query->get();

                // Generate full logo URLs and subscription snapshot for all tenants
                $tenants->each(fn ($tenant) => $this->decorateTenantForListResponse($tenant));

                $result = [
                    'success' => true,
                    'data' => $tenants,
                    'total' => $tenants->count(),
                ];
            } else {
                $tenants = $query->paginate($perPage);

                // Generate full logo URLs and subscription snapshot for all tenants
                $tenants->getCollection()->transform(
                    fn ($tenant) => $this->decorateTenantForListResponse($tenant)
                );

                $result = [
                    'success' => true,
                    'data' => $tenants->items(),
                    'pagination' => [
                        'current_page' => $tenants->currentPage(),
                        'last_page' => $tenants->lastPage(),
                        'per_page' => $tenants->perPage(),
                        'total' => $tenants->total(),
                        'from' => $tenants->firstItem(),
                        'to' => $tenants->lastItem(),
                    ],
                ];
            }

            return response()->json($result);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenants list: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching tenants',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific tenant by ID.
     *
     * @route GET /api/tenant/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::with([
                'creator',
                'updater',
                'users',
                'primaryContact.addresses',
                'secondaryContact.addresses',
                'addresses',
            ])->findOrFail($id);

            // Generate full logo URL
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
            }

            $subscriptionStatus = $this->subscriptionService->resolveStatus($tenant);

            return response()->json([
                'success' => true,
                'data' => $tenant,
                'stats' => [
                    'total_users' => $tenant->users()->count(),
                    'active_users' => $tenant->users()->where('active', 1)->count(),
                    'remaining_slots' => $tenant->getRemainingUserSlots(),
                    'has_active_subscription' => $tenant->hasActiveSubscription(),
                    'is_in_trial' => $tenant->isInTrial(),
                    'subscription_status' => $subscriptionStatus,
                    'allows_gated_access' => $this->subscriptionService->allowsGatedAccess($tenant),
                    'subscription_suspended_at' => $tenant->subscription_suspended_at?->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenant: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Tenant not found',
            ], 404);
        }
    }

    /**
     * Platform-admin 360° tenant snapshot for the explicitly requested tenant.
     *
     * @route GET /api/tenant/{id}/details
     */
    public function details(int $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $snapshot = $this->tenantDetailsService->build($id, auth()->user());

            return response()->json([
                'success' => true,
                'data' => new TenantDetailsResource($snapshot),
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenant details snapshot: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching tenant details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create a new tenant with normalized structure.
     *
     * @route POST /api/tenant
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        if (! $this->canManageTenants()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        DB::beginTransaction();

        try {
            // Step 1: Create the tenant
            $planAssigner = app()->bound(TenantPlanAssigner::class) ? app(TenantPlanAssigner::class) : null;
            $plan = $request->plan ?? 'free';
            $trialDays = config('tenants.trial_days', 30);

            // For Free plan, set 30-day trial if not explicitly provided.
            // With the plan catalog, the trial comes from the assigned plan version instead.
            $trialEndsAt = $request->trial_ends_at;
            if (! $planAssigner && $plan === 'free' && ! $trialEndsAt) {
                $trialEndsAt = now()->addDays($trialDays);
            }

            $features = $request->features;
            if (! is_array($features) || count($features) === 0) {
                $planModel = SubscriptionPlan::query()->where('key', $plan)->first();
                $features = $planModel?->features
                    ?? (array) (config("tenants.plans.{$plan}.features") ?? []);
            }

            $tenantData = [
                'name' => $request->tenant_name,
                'slogan' => $request->slogan,
                'slug' => $request->slug ?? Str::slug($request->tenant_name),
                'domain' => $request->domain,
                'plan' => $plan,
                'max_users' => $request->max_users ?? 10,
                'max_storage_mb' => $request->max_storage_mb ?? 100,
                'trial_ends_at' => $trialEndsAt,
                'subscription_ends_at' => $request->subscription_ends_at,
                'primary_color' => $request->primary_color ?? '#3B82F6',
                'secondary_color' => $request->secondary_color ?? '#10B981',
                'settings' => $request->settings ?? [],
                'features' => $features,
            ];

            // Create tenant first (needed for tenant ID in file path)
            $tenant = Tenant::create($tenantData);

            // Catalog-managed plan: entitlements, limits and legacy columns come from the plan
            // version (request-supplied features/limits are not trusted).
            if ($planAssigner) {
                $actor = auth()->user();
                $tenant = $planAssigner->assignInitialPlan(
                    $tenant,
                    $request->filled('plan') ? (string) $request->plan : null,
                    $actor?->id,
                    $actor?->role?->name,
                );
            }

            // SECURITY: Handle logo upload AFTER tenant creation with tenant-specific path
            if ($request->hasFile('tenant_logo')) {
                try {
                    $result = $this->fileUploadService->storeTenantLogo(
                        $request->file('tenant_logo'),
                        $tenant->id,
                    );
                    $tenant->logo_url = $result->storageKey;
                    $tenant->save();
                } catch (ImageMediaException $e) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => $e->publicMessage(),
                    ], 422);
                }
            }

            // Step 1.5: Create tenant official address (stored as 'official' type)
            if ($request->tenant_official_address && is_array($request->tenant_official_address)) {
                $this->addressService->create(
                    $tenant,
                    $request->tenant_official_address,
                    'official',  // Mark as tenant's official address
                    true  // Set as default
                );
                app(ChurchCurrencyResolver::class)->syncDerivedColumns((int) $tenant->id);
                app(ChurchFinancialPeriodResolver::class)->syncDerivedFyStartColumns((int) $tenant->id);
            }

            // Step 1.6: Seed church profile diocese when provided at creation time
            if ($request->filled('archdiocese_id')) {
                ChurchProfile::create([
                    'tenant_id' => $tenant->id,
                    'archdiocese_id' => (int) $request->archdiocese_id,
                ]);
            }

            // Step 2: Create Administrator role for the tenant
            // Each tenant gets their own "Administrator" role (unique per tenant via composite constraint)

            // Fix PostgreSQL sequence synchronization issue
            // Reset the sequence to the max ID + 1 to avoid duplicate key errors
            try {
                $maxId = (int) (DB::table('roles')->max('id') ?? 0);
                $nextId = max($maxId, 0) + 1;
                // Set sequence to next available ID (safe since $nextId is a controlled integer)
                DB::statement("SELECT setval('roles_id_seq', {$nextId}, true)");
                Log::info('Roles sequence synchronized', ['max_id' => $maxId, 'next_id' => $nextId]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                Log::warning('Could not reset roles sequence, continuing anyway', ['error' => $e->getMessage()]);
            }

            $adminRole = Role::create([
                'name' => 'Administrator',
                'description' => "{$tenant->name} Super Administrator",
                'level' => 1,
                'tenant_id' => $tenant->id,
                'is_custom' => false, // System-level role for tenant administration
                'role_type' => Role::ROLE_TYPE_TENANT,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
                'active' => 1,
            ]);

            Log::info('Administrator role created for tenant', [
                'tenant_id' => $tenant->id,
                'role_id' => $adminRole->id,
                'role_name' => $adminRole->name,
            ]);

            // Step 2.1: Create default tenant role catalog.
            $defaultRoles = [
                ['name' => 'Parish Priest', 'description' => "{$tenant->name} Parish Priest", 'level' => 2],
            ];

            $defaultRoleMap = [];
            foreach ($defaultRoles as $defaultRole) {
                $role = Role::create([
                    'name' => $defaultRole['name'],
                    'description' => $defaultRole['description'],
                    'level' => $defaultRole['level'],
                    'tenant_id' => $tenant->id,
                    'is_custom' => false,
                    'role_type' => Role::ROLE_TYPE_TENANT,
                    'role_classification' => Role::CLASSIFICATION_DEFAULT_TEMPLATE,
                    'active' => 1,
                ]);
                $defaultRoleMap[$defaultRole['name']] = $role;
            }

            // Step 2.1: Assign all tenant-relevant permissions to Administrator role
            // Get all active system permissions (excluding custom permissions)
            $tenantPermissionCatalog = Permission::where('active', 1)
                ->where('is_custom', false)  // Only system permissions
                ->whereNull('tenant_id')      // Global permissions
                ->where(function ($query) {
                    $query->whereNull('scope')
                        ->orWhereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH]);
                })
                ->get(['id', 'name']);
            $tenantPermissions = $tenantPermissionCatalog
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $parishPriestPermissions = $this->buildParishPriestPermissionIds($tenantPermissionCatalog);

            if (! empty($tenantPermissions)) {
                // Assign all permissions to the Administrator role
                $adminRole->permissions()->sync($tenantPermissions);

                Log::info('Permissions assigned to Administrator role', [
                    'tenant_id' => $tenant->id,
                    'role_id' => $adminRole->id,
                    'permissions_count' => count($tenantPermissions),
                ]);
            } else {
                Log::warning('No system permissions found to assign to Administrator role', [
                    'tenant_id' => $tenant->id,
                    'role_id' => $adminRole->id,
                ]);
            }

            // Step 2.2: Seed operational permissions for default Parish Priest role.
            $this->seedDefaultTemplateRolePermissions($defaultRoleMap, $parishPriestPermissions);

            // Step 3: Create primary contact user with Administrator role
            $primaryUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->primary_user_name,
                'email' => $request->primary_user_email,
                'contact_number' => $request->primary_contact_number,
                'user_type' => User::USER_TYPE_PRIMARY_CONTACT,
                'is_primary_admin' => true,
                'role_id' => $adminRole->id,
                'password' => Hash::make($request->primary_user_password),
                'active' => 1,
            ]);
            $primaryUser->forceFill([
                'force_password_change' => true,
                'password_changed_at' => now(),
            ])->save();

            // Keep legacy single-role field and new multi-role pivot in sync.
            $primaryUser->roles()->syncWithoutDetaching([$adminRole->id]);

            Log::info('Primary user created and assigned Administrator role', [
                'tenant_id' => $tenant->id,
                'user_id' => $primaryUser->id,
                'role_id' => $adminRole->id,
                'user_email' => $primaryUser->email,
            ]);

            // Step 4: Create primary contact address
            if ($request->primary_user_address && is_array($request->primary_user_address)) {
                $this->addressService->create(
                    $primaryUser,
                    $request->primary_user_address,
                    'primary',
                    true  // Set as default
                );
            }

            // Step 5: Create secondary contact user (if provided)
            if ($request->secondary_user_name && $request->secondary_user_email) {
                $secondaryUser = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $request->secondary_user_name,
                    'email' => $request->secondary_user_email,
                    'contact_number' => $request->secondary_contact_number,
                    'user_type' => User::USER_TYPE_SECONDARY_CONTACT,
                    'password' => Hash::make('TempPassword123!'),
                    'active' => 1,
                ]);
                $secondaryUser->forceFill([
                    'force_password_change' => true,
                    'password_changed_at' => now(),
                ])->save();

                // Step 6: Create secondary contact address (if provided)
                if ($request->secondary_user_address && is_array($request->secondary_user_address)) {
                    $this->addressService->create(
                        $secondaryUser,
                        $request->secondary_user_address,
                        'primary',  // Primary address for secondary user
                        true  // Set as default
                    );
                }
            }

            // Reload tenant with all relationships
            $tenant = Tenant::with([
                'addresses',  // Include tenant's official address
                'primaryContact.addresses',
                'secondaryContact.addresses',
            ])->find($tenant->id);

            // Seed default Ministries & Associations taxonomy + Youth Association (idempotent).
            if (class_exists(MinistriesAssociationsDefaultSeeder::class)) {
                (new MinistriesAssociationsDefaultSeeder)->run(
                    (int) $tenant->id,
                    (int) $primaryUser->id,
                );

                Log::info('Ministries & Associations defaults seeded for tenant', [
                    'tenant_id' => $tenant->id,
                ]);
            }

            if (class_exists(TenantSacramentSettingsService::class)) {
                app(TenantSacramentSettingsService::class)->ensureDefaults(
                    (int) $tenant->id,
                    (int) $primaryUser->id,
                );

                Log::info('Sacrament settings seeded for tenant', [
                    'tenant_id' => $tenant->id,
                ]);
            }

            Log::info('Tenant created successfully with normalized structure', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'primary_user_id' => $primaryUser->id,
                'created_by' => auth()->id(),
            ]);

            // Generate full logo URL for response
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant created successfully',
                'data' => $tenant,
            ], 201);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            DB::rollBack();

            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }

            Log::error('Error creating tenant: '.$e->getMessage(), [
                'request_data' => $request->except(['tenant_logo']),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Seed baseline permissions for default template roles.
     * These are starter templates and remain fully editable by tenant admins.
     */
    private function seedDefaultTemplateRolePermissions(array $defaultRoleMap, array $tenantPermissionIds): void
    {
        if (empty($defaultRoleMap) || empty($tenantPermissionIds)) {
            return;
        }

        foreach ($defaultRoleMap as $role) {
            if (! $role instanceof Role) {
                continue;
            }

            // Don't overwrite if tenant has already customized role permissions.
            if ($role->permissions()->count() > 0) {
                continue;
            }

            $role->permissions()->sync($tenantPermissionIds);
        }
    }

    /**
     * Parish Priest is default operational leadership, not governance authority.
     */
    private function buildParishPriestPermissionIds($permissions): array
    {
        return $permissions
            ->reject(function (Permission $permission) {
                return $this->isGovernancePermissionName($permission->name)
                    || in_array($permission->name, [
                        'support.tickets.view_all_tenant',
                        'support.tickets.cancel',
                    ], true);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function isGovernancePermissionName(string $permissionName): bool
    {
        $governancePrefixes = [
            'roles.',
            'permissions.',
            'users.assign',
            'users.create',
            'users.update',
            'users.delete',
            'users.password.reset_subordinates',
            'tenant.admin_password.reset',
            'church.settings.',
            'settings.',
            'security.',
            'integration.',
            'integrations.',
            'subscription.',
            'billing.',
            'tenants.',
            'pope.',
        ];

        foreach ($governancePrefixes as $prefix) {
            if (str_starts_with($permissionName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update an existing tenant with normalized structure.
     *
     * @route PUT /api/tenant/{id}
     */
    public function update(UpdateTenantRequest $request, $id): JsonResponse
    {
        if (! $this->canManageTenants()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $tenant = Tenant::with(['primaryContact', 'secondaryContact'])->findOrFail($id);

            // Step 1: Update tenant basic info
            $tenantUpdateData = [];

            // With the plan catalog, plan and entitlement columns are written only by the
            // subscription plan-change service (upgrade endpoint / admin subscription APIs).
            $catalogManagedKeys = app()->bound(TenantPlanAssigner::class)
                ? ['plan', 'max_users', 'max_storage_mb', 'features']
                : [];

            foreach (['tenant_name' => 'name', 'slogan', 'slug', 'domain', 'plan', 'max_users',
                'max_storage_mb', 'trial_ends_at', 'subscription_ends_at', 'active',
                'primary_color', 'secondary_color', 'settings', 'features'] as $key => $dbKey) {
                $requestKey = is_int($key) ? $dbKey : $key;
                if (in_array($dbKey, $catalogManagedKeys, true)) {
                    continue;
                }
                if ($request->has($requestKey)) {
                    $tenantUpdateData[$dbKey] = $request->$requestKey;
                }
            }

            // SECURITY: Handle logo upload with tenant-specific path
            if ($request->hasFile('tenant_logo')) {
                $previousLogo = $tenant->logo_url;
                $result = $this->fileUploadService->replaceTenantLogo(
                    $request->file('tenant_logo'),
                    $tenant->id,
                    $previousLogo,
                    function (string $storageKey) use ($tenant): void {
                        DB::transaction(function () use ($tenant, $storageKey): void {
                            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                            $locked->logo_url = $storageKey;
                            $locked->save();
                        });
                    }
                );
                $tenantUpdateData['logo_url'] = $result->storageKey;
            }

            if (! empty($tenantUpdateData)) {
                $tenant->update($tenantUpdateData);
            }

            // Step 1.4: Update church profile fields when provided
            $churchProfileUpdates = [];
            if ($request->exists('denomination_id')) {
                $denominationId = $request->input('denomination_id');
                $churchProfileUpdates['denomination_id'] = $denominationId ? (int) $denominationId : null;
            }
            if ($request->exists('archdiocese_id')) {
                $archdioceseId = $request->input('archdiocese_id');
                $churchProfileUpdates['archdiocese_id'] = $archdioceseId ? (int) $archdioceseId : null;
            }
            if ($request->exists('website')) {
                $website = trim((string) $request->input('website'));
                $churchProfileUpdates['website'] = $website !== '' ? $website : null;
            }
            if ($churchProfileUpdates !== []) {
                ChurchProfile::updateOrCreate(
                    ['tenant_id' => $tenant->id],
                    $churchProfileUpdates
                );
            }

            // Step 1.5: Update tenant official address
            if ($request->has('tenant_official_address') && is_array($request->tenant_official_address)) {
                $tenantAddress = $tenant->addresses()
                    ->where('address_type', 'official')
                    ->first();

                if ($tenantAddress) {
                    // Update existing address
                    $this->addressService->update($tenantAddress, $request->tenant_official_address);
                } else {
                    // Create new address
                    $this->addressService->create(
                        $tenant,
                        $request->tenant_official_address,
                        'official',
                        true
                    );
                }
                app(ChurchCurrencyResolver::class)->syncDerivedColumns((int) $tenant->id);
                app(ChurchFinancialPeriodResolver::class)->syncDerivedFyStartColumns((int) $tenant->id);
            }

            // Step 2: Update primary contact user (if exists)
            if ($tenant->primaryContact) {
                $primaryUserUpdate = [];
                if ($request->has('primary_user_name')) {
                    $primaryUserUpdate['name'] = $request->primary_user_name;
                }
                if ($request->has('primary_user_email')) {
                    $primaryUserUpdate['email'] = $request->primary_user_email;
                }
                if ($request->has('primary_contact_number')) {
                    $primaryUserUpdate['contact_number'] = $request->primary_contact_number;
                }

                if (! empty($primaryUserUpdate)) {
                    $tenant->primaryContact->update($primaryUserUpdate);
                }

                // Update primary contact address
                if ($request->has('primary_user_address') && is_array($request->primary_user_address)) {
                    $primaryAddress = $tenant->primaryContact->addresses()
                        ->where('address_type', 'primary')
                        ->first();

                    if ($primaryAddress) {
                        $this->addressService->update($primaryAddress, $request->primary_user_address);
                    } else {
                        $this->addressService->create(
                            $tenant->primaryContact,
                            $request->primary_user_address,
                            'primary',
                            true
                        );
                    }
                }
            }

            // Step 3: Update secondary contact user
            if ($request->has('secondary_user_name') && $request->has('secondary_user_email')) {
                if ($tenant->secondaryContact) {
                    // Update existing secondary contact
                    $secondaryUserUpdate = [
                        'name' => $request->secondary_user_name,
                        'email' => $request->secondary_user_email,
                    ];
                    if ($request->has('secondary_contact_number')) {
                        $secondaryUserUpdate['contact_number'] = $request->secondary_contact_number;
                    }
                    $tenant->secondaryContact->update($secondaryUserUpdate);

                    // Update secondary contact address
                    if ($request->has('secondary_user_address') && is_array($request->secondary_user_address)) {
                        $secondaryAddress = $tenant->secondaryContact->addresses()
                            ->where('address_type', 'primary')
                            ->first();

                        if ($secondaryAddress) {
                            $this->addressService->update($secondaryAddress, $request->secondary_user_address);
                        } else {
                            $this->addressService->create(
                                $tenant->secondaryContact,
                                $request->secondary_user_address,
                                'primary',
                                true
                            );
                        }
                    }
                } else {
                    // Create new secondary contact
                    $secondaryUser = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $request->secondary_user_name,
                        'email' => $request->secondary_user_email,
                        'contact_number' => $request->secondary_contact_number,
                        'user_type' => User::USER_TYPE_SECONDARY_CONTACT,
                        'password' => Hash::make('TempPassword123!'),
                        'active' => 1,
                    ]);
                    $secondaryUser->forceFill([
                        'force_password_change' => true,
                        'password_changed_at' => now(),
                    ])->save();

                    if ($request->has('secondary_user_address') && is_array($request->secondary_user_address)) {
                        $this->addressService->create(
                            $secondaryUser,
                            $request->secondary_user_address,
                            'primary',
                            true
                        );
                    }
                }
            }

            // Reload tenant with relationships
            $tenant = Tenant::with([
                'addresses',  // Include tenant's official address
                'primaryContact.addresses',
                'secondaryContact.addresses',
            ])->find($id);

            Log::info('Tenant updated successfully with normalized structure', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'updated_by' => auth()->id(),
            ]);

            // Generate full logo URL for response
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant updated successfully',
                'data' => $tenant,
            ]);
        } catch (ImageMediaException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            DB::rollBack();

            Log::error('Error updating tenant: '.$e->getMessage(), [
                'tenant_id' => $id,
                'request_data' => $request->except(['tenant_logo']),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a tenant (soft delete).
     *
     * @route DELETE /api/tenant/{id}
     */
    public function destroy($id): JsonResponse
    {
        if (! $this->canManageTenants()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $tenant = Tenant::findOrFail($id);

            // Check if tenant has users
            $userCount = $tenant->users()->count();
            if ($userCount > 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete tenant with {$userCount} existing user(s). Please remove or transfer users first.",
                ], 422);
            }

            // Soft delete
            $tenant->delete();

            Log::warning('Tenant deleted', [
                'tenant_id' => $id,
                'tenant_name' => $tenant->name,
                'deleted_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            DB::rollBack();

            Log::error('Error deleting tenant: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update tenant active status.
     *
     * @route PATCH /api/tenant/{id}/status
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            // Check authorization
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage tenants.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'active' => 'required|integer|in:0,1',
                'description' => 'nullable|string|max:500',
            ]);

            // Find tenant
            $tenant = Tenant::find($id);

            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Store old status for logging
            $oldStatus = $tenant->active;

            // Update status
            $tenant->active = $request->active;
            $tenant->updated_by = auth()->id();
            $tenant->save();

            // Create audit log entry in database
            try {
                TenantStatusAudit::log(
                    tenant: $tenant,
                    previousStatus: $oldStatus,
                    newStatus: $tenant->active,
                    reason: $request->description,
                    performedBy: auth()->id(),
                    additionalData: [
                        'user_name' => auth()->user()->name ?? 'Unknown',
                        'user_email' => auth()->user()->email ?? 'Unknown',
                    ]
                );
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                // Log error but don't fail the status update
                Log::error('Failed to create audit log entry', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Log status change to application log
            Log::info('Tenant status updated', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'old_status' => $oldStatus,
                'new_status' => $tenant->active,
                'description' => $request->description ?? 'No description provided',
                'updated_by' => auth()->id(),
                'updated_by_name' => auth()->user()->name ?? 'Unknown',
            ]);

            // Load relationships for response
            $tenant->load(['creator', 'updater', 'addresses', 'primaryContact.addresses', 'secondaryContact.addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Tenant status updated successfully',
                'data' => $tenant,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating tenant status: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating tenant status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload or update tenant logo.
     *
     * @route POST /api/tenant/{id}/logo
     */
    public function uploadLogo(UploadTenantLogoRequest $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::findOrFail($id);

            $result = $this->fileUploadService->replaceTenantLogo(
                $request->file('logo'),
                $tenant->id,
                $tenant->logo_url,
                function (string $storageKey) use ($tenant): void {
                    DB::transaction(function () use ($tenant, $storageKey): void {
                        $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                        $locked->logo_url = $storageKey;
                        $locked->save();
                    });
                }
            );

            Log::info('Tenant logo uploaded', [
                'tenant_id' => $id,
                'uploaded_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo uploaded successfully',
                'data' => [
                    'logo_full_url' => $this->fileUploadService->getTenantLogoUrl($result->storageKey, $tenant->id),
                ],
            ]);
        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error uploading logo: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error uploading logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete tenant logo.
     *
     * @route DELETE /api/tenant/{id}/logo
     */
    public function deleteLogo($id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::findOrFail($id);

            if (! $tenant->logo_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant has no logo',
                ], 404);
            }

            $previousLogo = $tenant->logo_url;

            DB::transaction(function () use ($tenant): void {
                $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $locked->logo_url = null;
                $locked->save();
            });

            $this->fileUploadService->deleteTenantLogo($previousLogo, $tenant->id);

            Log::info('Tenant logo deleted', [
                'tenant_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting logo: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Tenant counts keyed by every plan key in the catalog (zero-filled).
     *
     * @return array<string, int>
     */
    private function tenantCountsByPlan(): array
    {
        $counts = array_fill_keys(
            SubscriptionPlan::withTrashed()->pluck('key')->map(static fn ($k) => (string) $k)->all(),
            0
        );

        foreach (Tenant::query()->selectRaw('plan, COUNT(*) as aggregate')->groupBy('plan')->pluck('aggregate', 'plan') as $key => $count) {
            if ($key !== null && $key !== '') {
                $counts[(string) $key] = (int) $count;
            }
        }

        return $counts;
    }

    /**
     * Get tenant statistics.
     *
     * @route GET /api/tenant/statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $stats = [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::active()->count(),
                'inactive_tenants' => Tenant::inactive()->count(),
                'tenants_by_plan' => $this->tenantCountsByPlan(),
                'in_trial' => Tenant::inTrial()->count(),
                'subscribed' => Tenant::subscribed()->count(),
                'recent_tenants' => Tenant::orderBy('created_at', 'desc')->take(5)->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenant statistics: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get church profile for the authenticated tenant user.
     * Returns the tenant record associated with the user's tenant_id.
     *
     * @route GET /api/tenant/church-profile
     */
    public function getChurchProfile(): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();

            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Load tenant with all relationships including ecclesiastical data
            $tenant = Tenant::with([
                'creator',
                'updater',
                'addresses',
                'primaryContact.addresses',
                'secondaryContact.addresses',
                // Ecclesiastical relationships
                'churchProfile.denomination',
                'churchProfile.archdiocese.denomination',
                'churchProfile.bishop.archdiocese',
                'churchLeadership' => function ($query) {
                    $query->active()->current()->ordered();
                },
                'activeSocialMedia',
                'churchStatistics' => function ($query) {
                    $query->latest()->limit(12); // Last 12 records
                },
            ])->find($tenantId);

            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church profile not found',
                ], 404);
            }

            // Generate full logo URL
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
            }

            Log::info('Church profile retrieved', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'support_session_id' => app(TenantContext::class)->supportSessionId(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $tenant,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching church profile: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload or replace the signed-in parish's logo.
     * Tenant id comes from the session, not the request.
     *
     * @route POST /api/tenant/church-profile/logo
     */
    public function uploadChurchLogo(UploadChurchProfileLogoRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();

            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (! $this->canUpdateChurchProfileShell($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can update the church logo.',
                ], 403);
            }

            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church profile not found',
                ], 404);
            }

            $result = $this->fileUploadService->replaceTenantLogo(
                $request->file('logo'),
                $tenant->id,
                $tenant->logo_url,
                function (string $storageKey) use ($tenant): void {
                    DB::transaction(function () use ($tenant, $storageKey): void {
                        $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                        $locked->logo_url = $storageKey;
                        $locked->save();
                    });
                }
            );

            Log::info('Church logo uploaded', [
                'tenant_id' => $tenant->id,
                'uploaded_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo uploaded successfully',
                'data' => [
                    'logo_full_url' => $this->fileUploadService->getTenantLogoUrl($result->storageKey, $tenant->id),
                ],
            ]);
        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error uploading church logo: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error uploading logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the signed-in parish's logo.
     *
     * @route DELETE /api/tenant/church-profile/logo
     */
    public function deleteChurchLogo(): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();

            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (! $this->canUpdateChurchProfileShell($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can update the church logo.',
                ], 403);
            }

            $tenant = Tenant::find($tenantId);

            if (! $tenant || ! $tenant->logo_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church has no logo',
                ], 404);
            }

            $previousLogo = $tenant->logo_url;

            DB::transaction(function () use ($tenant): void {
                $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $locked->logo_url = null;
                $locked->save();
            });

            $this->fileUploadService->deleteTenantLogo($previousLogo, $tenant->id);

            Log::info('Church logo deleted', [
                'tenant_id' => $tenant->id,
                'deleted_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting church logo: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update church profile for the authenticated tenant user.
     * Allows tenant users (especially primary admin) to update their church information.
     *
     * @route PUT /api/tenant/church-profile
     */
    public function updateChurchProfile(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();

            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (! $this->canUpdateChurchProfileShell($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can update church profile.',
                ], 403);
            }

            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church profile not found',
                ], 404);
            }

            // Validation rules for church profile update
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'slogan' => 'nullable|string|max:500',
                'denomination' => 'nullable|string|max:100',
                'founded_year' => 'nullable|integer|min:1000|max:'.(date('Y') + 1),
                'pastor_name' => 'nullable|string|max:255',
                'associate_pastors' => 'nullable|string|max:500',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'website' => 'nullable|url|max:255',
                'about' => 'nullable|string|max:5000',
                'vision' => 'nullable|string|max:2000',
                'mission' => 'nullable|string|max:2000',
                'core_values' => 'nullable|string|max:2000',
                'service_times' => 'nullable|string|max:1000',
                'weekly_attendance' => 'nullable|integer|min:0',
                'membership_count' => 'nullable|integer|min:0',
                'social_facebook' => 'nullable|url|max:255',
                'social_twitter' => 'nullable|url|max:255',
                'social_instagram' => 'nullable|url|max:255',
                'social_youtube' => 'nullable|url|max:255',
                'primary_color' => 'nullable|string|max:7', // Hex color
                'secondary_color' => 'nullable|string|max:7', // Hex color
            ]);

            DB::beginTransaction();
            try {
                // Update tenant
                $tenant->update($validated);

                DB::commit();

                // Reload relationships including ecclesiastical data
                $tenant->load([
                    'creator',
                    'updater',
                    'addresses',
                    'primaryContact.addresses',
                    'secondaryContact.addresses',
                    'churchProfile.denomination',
                    'churchProfile.archdiocese.denomination',
                    'churchProfile.bishop.archdiocese',
                    'churchLeadership' => function ($query) {
                        $query->active()->current()->ordered();
                    },
                    'activeSocialMedia',
                    'churchStatistics' => function ($query) {
                        $query->latest()->limit(12);
                    },
                ]);

                // Generate full logo URL
                if ($tenant->logo_url) {
                    $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
                }

                Log::info('Church profile updated', [
                    'tenant_id' => $tenant->id,
                    'updated_by' => $user->id,
                    'changes' => $validated,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church profile updated successfully',
                    'data' => $tenant,
                ]);
            } catch (\Exception $e) {
                ApiErrorResponse::rethrowExpected($e);
                DB::rollBack();
                throw $e;
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating church profile: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upgrade tenant subscription plan (admin entitlement grant — no payment).
     *
     * @route POST /api/tenant/{id}/subscription/upgrade
     */
    public function upgradeSubscription(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscriptions.',
                ], 403);
            }

            $validated = $request->validate([
                'plan' => 'required|string|max:255',
                'subscription_duration_months' => 'nullable|integer|min:1|max:120',
                'reason' => 'nullable|string|max:500',
            ]);

            $tenant = Tenant::findOrFail($id);
            $user = auth()->user();

            $tenant = $this->subscriptionService->applyPlan($tenant, [
                'plan' => $validated['plan'],
                'subscription_duration_months' => $validated['subscription_duration_months'] ?? 12,
                'reason' => $validated['reason'] ?? null,
                'source' => 'admin_ui',
            ], $user?->id, $user?->role_name ?? $user?->role?->name);

            $plan = SubscriptionPlan::query()->where('key', $tenant->plan)->first();

            return response()->json([
                'success' => true,
                'message' => 'Subscription upgraded successfully',
                'data' => [
                    'tenant' => $tenant->fresh(),
                    'plan_details' => $plan,
                    'subscription_status' => $this->subscriptionService->resolveStatus($tenant),
                    'subscription_ends_at' => $tenant->subscription_ends_at?->toDateTimeString(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            if ($e instanceof Responsable) {
                return $e->toResponse($request);
            }
            $message = match ($e->getMessage()) {
                'invalid_plan' => 'Invalid or inactive subscription plan',
                'downgrade_not_allowed' => 'Cannot downgrade to this plan. Only downgrades to Free plan are allowed for special cases.',
                'duration_not_in_catalog', 'invalid_duration' => 'Invalid subscription duration',
                default => 'Error upgrading subscription',
            };
            $code = in_array($e->getMessage(), ['invalid_plan', 'downgrade_not_allowed', 'duration_not_in_catalog', 'invalid_duration'], true) ? 422 : 500;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $code);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error upgrading subscription: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error upgrading subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Renew tenant subscription (admin date extension — no payment).
     *
     * @route POST /api/tenant/{id}/subscription/renew
     */
    public function renewSubscription(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscriptions.',
                ], 403);
            }

            $validated = $request->validate([
                'duration_months' => 'nullable|integer|min:1|max:120',
                'reason' => 'nullable|string|max:500',
            ]);

            $tenant = Tenant::findOrFail($id);
            $user = auth()->user();

            $tenant = $this->subscriptionService->renew($tenant, [
                'duration_months' => $validated['duration_months'] ?? 12,
                'reason' => $validated['reason'] ?? null,
                'source' => 'admin_ui',
            ], $user?->id, $user?->role_name ?? $user?->role?->name);

            return response()->json([
                'success' => true,
                'message' => 'Subscription renewed successfully',
                'data' => [
                    'tenant' => $tenant->fresh(),
                    'subscription_status' => $this->subscriptionService->resolveStatus($tenant),
                    'subscription_ends_at' => $tenant->subscription_ends_at?->toDateTimeString(),
                    'duration_months' => (int) ($validated['duration_months'] ?? 12),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid subscription duration',
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error renewing subscription: '.$e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error renewing subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Suspend tenant subscription access (admin soft-block).
     *
     * @route POST /api/tenant/{id}/subscription/suspend
     */
    public function suspendSubscription(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscriptions.',
                ], 403);
            }

            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $tenant = Tenant::findOrFail($id);
            $user = auth()->user();
            $tenant = $this->subscriptionService->suspend(
                $tenant,
                $validated['reason'] ?? null,
                $user?->id,
                $user?->role_name ?? $user?->role?->name
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription suspended successfully',
                'data' => [
                    'tenant' => $tenant->fresh(),
                    'subscription_status' => $this->subscriptionService->resolveStatus($tenant),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error suspending subscription: '.$e->getMessage(), ['tenant_id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Error suspending subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Reactivate a suspended subscription.
     *
     * @route POST /api/tenant/{id}/subscription/reactivate
     */
    public function reactivateSubscription(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscriptions.',
                ], 403);
            }

            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);

            $tenant = Tenant::findOrFail($id);
            $user = auth()->user();
            $tenant = $this->subscriptionService->reactivate(
                $tenant,
                $validated['reason'] ?? null,
                $user?->id,
                $user?->role_name ?? $user?->role?->name
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription reactivated successfully',
                'data' => [
                    'tenant' => $tenant->fresh(),
                    'subscription_status' => $this->subscriptionService->resolveStatus($tenant),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error reactivating subscription: '.$e->getMessage(), ['tenant_id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Error reactivating subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Super Admin: paginated subscription audit history for a tenant.
     *
     * @route GET /api/tenant/{id}/subscription/audits
     */
    public function subscriptionAudits(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();
            if (! $user || ! $user->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin can view subscription audits.',
                ], 403);
            }

            $tenant = Tenant::find($id);
            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            $validated = $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:50',
                'page' => 'sometimes|integer|min:1',
                'operation' => 'sometimes|nullable|string|max:64',
            ]);

            $result = $this->subscriptionService->listAuditsForTenant((int) $id, $validated);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'meta' => [
                    'operations' => $this->subscriptionService->auditOperationLabels(),
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching subscription audits: '.$e->getMessage(), ['tenant_id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription audits',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Lightweight subscription access for any authenticated tenant user (nav/banners).
     *
     * @route GET /api/tenant/subscription-access
     */
    public function subscriptionAccess(): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();
            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->subscriptionService->buildAccessSnapshot($tenant) + $this->entitlementAccessSummary($tenant, false),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching subscription access: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription access',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Additive plan/entitlement fields; the lifecycle payload must still load if resolution fails.
     *
     * @return array<string, mixed>
     */
    private function entitlementAccessSummary(Tenant $tenant, bool $withUsage): array
    {
        if (! app()->bound(TenantEntitlementGate::class)) {
            return [];
        }

        try {
            return app(TenantEntitlementGate::class)->accessSummary($tenant, $withUsage);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            report($e);

            return [];
        }
    }

    /**
     * Tenant read-only subscription summary (auth tenant context).
     *
     * @route GET /api/tenant/my-subscription
     */
    public function mySubscription(): JsonResponse
    {
        try {
            $user = auth()->user();
            $tenantId = app(TenantContext::class)->effectiveTenantId();
            if (! $user || $tenantId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (
                ! $user->isSuperAdmin()
                && ! $user->isEkklesiaAdmin()
                && ! $user->is_primary_admin
                && ! $user->isTenantAdmin()
                && ! app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)
                && method_exists($user, 'hasPermission')
                && ! $user->hasPermission('subscription.view')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view subscription.',
                ], 403);
            }

            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Church not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->subscriptionService->buildSummary($tenant) + $this->entitlementAccessSummary($tenant, true),
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching my subscription: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Super Admin: effective subscription access policy for a tenant.
     *
     * @route GET /api/tenant/{id}/subscription/access-policy
     */
    public function getTenantSubscriptionAccessPolicy(int $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription settings.',
                ], 403);
            }

            $tenant = Tenant::query()->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->subscriptionService->buildAccessPolicyPayload($tenant),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching tenant subscription access policy: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription access policy',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Super Admin: update per-tenant grace / view-only overrides (null resets to global).
     *
     * @route PUT /api/tenant/{id}/subscription/access-policy
     */
    public function updateTenantSubscriptionAccessPolicy(UpdateTenantSubscriptionAccessPolicyRequest $request, int $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription settings.',
                ], 403);
            }

            $tenant = Tenant::query()->findOrFail($id);
            $user = auth()->user();
            $tenant = $this->subscriptionService->updateTenantAccessPolicy(
                $tenant,
                $request->validated(),
                $user?->id,
                $user?->role_name ?? $user?->role?->name
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription access policy updated successfully',
                'data' => $this->subscriptionService->buildAccessPolicyPayload($tenant),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating tenant subscription access policy: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating subscription access policy',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Super Admin: get platform subscription settings (includes configurable grace days).
     *
     * @route GET /api/subscription/settings
     */
    public function getSubscriptionSettings(): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription settings.',
                ], 403);
            }

            $settings = $this->subscriptionService->getSettings();

            return response()->json([
                'success' => true,
                'data' => [
                    'grace_period_days' => (int) $settings->grace_period_days,
                    'expiring_warning_days' => (int) $settings->expiring_warning_days,
                    'view_only_allowance_days' => (int) ($settings->view_only_allowance_days ?? config('tenants.subscription.view_only_allowance_days', 90)),
                ],
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching subscription settings: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription settings',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Super Admin: update platform subscription settings (grace days, etc.).
     *
     * @route PUT /api/subscription/settings
     */
    public function updateSubscriptionSettings(UpdateSubscriptionSettingsRequest $request): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription settings.',
                ], 403);
            }

            $validated = $request->validated();

            $user = auth()->user();
            $settings = $this->subscriptionService->updateSettings(
                $validated,
                $user?->id,
                $user?->role_name ?? $user?->role?->name
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription settings updated successfully',
                'data' => [
                    'grace_period_days' => (int) $settings->grace_period_days,
                    'expiring_warning_days' => (int) $settings->expiring_warning_days,
                    'view_only_allowance_days' => (int) ($settings->view_only_allowance_days ?? 90),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating subscription settings: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating subscription settings',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available subscription plans.
     *
     * @route GET /api/tenant/subscription/plans
     */
    public function getSubscriptionPlans(): JsonResponse
    {
        try {
            // Get subscription plans from database
            $plans = SubscriptionPlan::active()
                ->ordered()
                ->get()
                ->map(function ($plan) {
                    return [
                        'key' => $plan->key,
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'price' => (float) $plan->price,
                        'max_users' => $plan->max_users,
                        'max_storage_mb' => $plan->max_storage_mb,
                        'features' => $plan->features ?? [],
                    ];
                })
                ->keyBy('key')
                ->toArray();

            $currency = config('tenants.default_settings.currency', 'INR');

            // Get subscription duration options from database
            $durationOptions = SubscriptionDurationOption::active()
                ->ordered()
                ->get(['months', 'label'])
                ->map(function ($option) {
                    return [
                        'value' => $option->months,
                        'label' => $option->label,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $plans,
                'currency' => $currency,
                'duration_options' => $durationOptions,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching subscription plans: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription plans',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get all subscription duration options.
     *
     * @route GET /api/subscription/duration-options
     */
    public function getDurationOptions(): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription duration options.',
                ], 403);
            }

            $options = SubscriptionDurationOption::ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $options,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching duration options: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching duration options',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create a new subscription duration option.
     *
     * @route POST /api/subscription/duration-options
     */
    public function createDurationOption(Request $request): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription duration options.',
                ], 403);
            }

            $validated = $request->validate([
                'months' => 'required|integer|min:1|max:120|unique:subscription_duration_options,months',
                'label' => 'required|string|max:255',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            $option = SubscriptionDurationOption::create([
                'months' => $validated['months'],
                'label' => $validated['label'],
                'display_order' => $validated['display_order'] ?? 0,
                'active' => $validated['active'] ?? true,
            ]);

            Log::info('Subscription duration option created', [
                'id' => $option->id,
                'months' => $option->months,
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Duration option created successfully',
                'data' => $option,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating duration option: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating duration option',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a subscription duration option.
     *
     * @route PUT /api/subscription/duration-options/{id}
     */
    public function updateDurationOption(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription duration options.',
                ], 403);
            }

            $option = SubscriptionDurationOption::findOrFail($id);

            $validated = $request->validate([
                'months' => 'sometimes|required|integer|min:1|max:120|unique:subscription_duration_options,months,'.$id,
                'label' => 'sometimes|required|string|max:255',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            $option->update($validated);

            Log::info('Subscription duration option updated', [
                'id' => $option->id,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Duration option updated successfully',
                'data' => $option->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating duration option: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating duration option',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a subscription duration option.
     *
     * @route DELETE /api/subscription/duration-options/{id}
     */
    public function deleteDurationOption($id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription duration options.',
                ], 403);
            }

            $option = SubscriptionDurationOption::findOrFail($id);
            $option->delete();

            Log::info('Subscription duration option deleted', [
                'id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Duration option deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting duration option: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error deleting duration option',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function isCatalogManagedPlan(SubscriptionPlan $plan): bool
    {
        return app()->bound(TenantPlanAssigner::class) || ! empty($plan->getAttribute('code'));
    }

    private function catalogManagedPlanResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'PLAN_CHANGE_NOT_ALLOWED',
            'message' => 'Subscription plans are managed in the plan catalog (Subscriptions → Plans). Changes there are versioned and audited.',
        ], 409);
    }

    /**
     * Get all subscription plans.
     *
     * @route GET /api/subscription/plans
     */
    public function getPlans(): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription plans.',
                ], 403);
            }

            $plans = SubscriptionPlan::ordered()->get();

            return response()->json([
                'success' => true,
                'data' => $plans,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error fetching subscription plans: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription plans',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create a new subscription plan.
     *
     * @route POST /api/subscription/plans
     */
    public function createPlan(Request $request): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription plans.',
                ], 403);
            }

            if (app()->bound(TenantPlanAssigner::class)) {
                return $this->catalogManagedPlanResponse();
            }

            $validated = $request->validate([
                'key' => 'required|string|max:255|unique:subscription_plans,key',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric|min:0',
                'max_users' => 'required|integer|min:1',
                'max_storage_mb' => 'required|integer|min:1',
                'features' => 'nullable|array',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
                'is_default' => 'nullable|boolean',
            ]);

            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default']) {
                SubscriptionPlan::where('is_default', true)->update(['is_default' => false]);
            }

            $plan = SubscriptionPlan::create($validated);

            Log::info('Subscription plan created', [
                'id' => $plan->id,
                'key' => $plan->key,
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan created successfully',
                'data' => $plan->fresh(),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error creating subscription plan: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating subscription plan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a subscription plan.
     *
     * @route PUT /api/subscription/plans/{id}
     */
    public function updatePlan(Request $request, $id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription plans.',
                ], 403);
            }

            $plan = SubscriptionPlan::findOrFail($id);
            if ($this->isCatalogManagedPlan($plan)) {
                return $this->catalogManagedPlanResponse();
            }

            $validated = $request->validate([
                'key' => 'sometimes|required|string|max:255|unique:subscription_plans,key,'.$id,
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'sometimes|required|numeric|min:0',
                'max_users' => 'sometimes|required|integer|min:1',
                'max_storage_mb' => 'sometimes|required|integer|min:1',
                'features' => 'nullable|array',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
                'is_default' => 'nullable|boolean',
            ]);

            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default'] && ! $plan->is_default) {
                SubscriptionPlan::where('is_default', true)->where('id', '!=', $id)->update(['is_default' => false]);
            }

            $plan->update($validated);

            Log::info('Subscription plan updated', [
                'id' => $plan->id,
                'key' => $plan->key,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan updated successfully',
                'data' => $plan->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error updating subscription plan: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating subscription plan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a subscription plan.
     *
     * @route DELETE /api/subscription/plans/{id}
     */
    public function deletePlan($id): JsonResponse
    {
        try {
            if (! $this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage subscription plans.',
                ], 403);
            }

            $plan = SubscriptionPlan::findOrFail($id);
            if ($this->isCatalogManagedPlan($plan)) {
                return $this->catalogManagedPlanResponse();
            }

            // Inactive-plan policy: deactivate first; hard delete only when inactive + unused.
            if ($plan->active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete an active plan. Deactivate the plan first (tenants keep their current entitlements; new assigns will be blocked).',
                ], 422);
            }

            // Check if any tenants are using this plan
            $tenantCount = Tenant::where('plan', $plan->key)->count();
            if ($tenantCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete plan. {$tenantCount} tenant(s) are currently using this plan. Keep it inactive so existing tenants retain their snapshot.",
                ], 422);
            }

            $plan->delete();

            Log::info('Subscription plan deleted', [
                'id' => $id,
                'key' => $plan->key,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan deleted successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting subscription plan: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error deleting subscription plan',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Attach list-only presentation fields (logo URL, resolved subscription state).
     */
    private function decorateTenantForListResponse(Tenant $tenant): Tenant
    {
        if ($tenant->logo_url) {
            $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url, $tenant->id);
        }

        $tenant->subscription_status = $this->subscriptionService->resolveStatus($tenant);
        $tenant->access_mode = $this->subscriptionService->accessMode($tenant);

        return $tenant;
    }

    /**
     * Apply list filters for tenant index queries.
     */
    private function applyTenantListFilters($query, Request $request): void
    {
        if ($request->filled('active')) {
            $query->where('active', (int) $request->active);
        }

        if ($request->filled('plan')) {
            $query->where('plan', $request->plan);
        }

        if ($request->filled('tenant_tier')) {
            $query->where('tenant_tier', $request->tenant_tier);
        }

        if ($request->filled('archdiocese_id')) {
            $archdioceseId = (int) $request->archdiocese_id;
            $query->whereHas('churchProfile', function ($q) use ($archdioceseId) {
                $q->where('archdiocese_id', $archdioceseId);
            });
        }

        if ($request->filled('subscription_status')) {
            $status = $request->subscription_status;
            $graceDays = max(0, (int) SubscriptionSettings::current()->grace_period_days);

            if ($status === 'trial') {
                $query->inTrial();
            } elseif ($status === 'subscribed') {
                $query->whereNull('subscription_suspended_at')
                    ->where(function ($q) use ($graceDays) {
                        $q->whereNull('subscription_ends_at')
                            ->orWhere('subscription_ends_at', '>', now())
                            ->orWhere(function ($grace) use ($graceDays) {
                                $this->constrainSubscriptionEndPlusDays($grace, '>=', $graceDays, now());
                            });
                    });
            } elseif ($status === 'grace') {
                $query->whereNull('subscription_suspended_at')
                    ->whereNotNull('subscription_ends_at')
                    ->where('subscription_ends_at', '<=', now());
                $this->constrainSubscriptionEndPlusDays($query, '>=', $graceDays, now());
            } elseif ($status === 'suspended') {
                $query->whereNotNull('subscription_suspended_at');
            } elseif ($status === 'expired') {
                $query->whereNull('subscription_suspended_at')
                    ->whereNotNull('subscription_ends_at');
                $this->constrainSubscriptionEndPlusDays($query, '<', $graceDays, now());
                $query->where(function ($q) {
                    $q->whereNull('trial_ends_at')
                        ->orWhere('trial_ends_at', '<=', now());
                });
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhereHas('primaryContact', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('contact_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('secondaryContact', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('contact_number', 'like', "%{$search}%");
                    });
            });
        }
    }

    /**
     * Compare (subscription_ends_at + grace days) to $now using equivalent
     * (subscription_ends_at {op} now - grace days) so SQLite and PostgreSQL agree.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function constrainSubscriptionEndPlusDays($query, string $operator, int $days, mixed $now): void
    {
        if (! in_array($operator, ['>=', '<=', '>', '<'], true)) {
            throw new \InvalidArgumentException('Unsupported comparison operator.');
        }

        $at = $now instanceof \Illuminate\Support\Carbon ? $now->copy() : now();
        $cutoff = $at->subDays(max(0, $days));

        $query->where('subscription_ends_at', $operator, $cutoff);
    }

    /**
     * Apply safe sorting for tenant index queries.
     */
    private function applyTenantListSorting($query, Request $request): void
    {
        $sortBy = $request->get('sort_by', 'created_at');
        if (! in_array($sortBy, self::TENANT_LIST_SORT_COLUMNS, true)) {
            $sortBy = 'created_at';
        }

        $sortOrder = strtolower((string) $request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);
    }

    /**
     * Check if current user can manage tenants.
     * Only SuperAdmin and EkklesiaAdmin can manage tenants.
     */
    private function canManageTenants(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // SuperAdmin and EkklesiaAdmin can manage all tenants
        return $user->isSuperAdmin() || $user->isEkklesiaAdmin();
    }

    private function canUpdateChurchProfileShell(User $user): bool
    {
        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)) {
            return true;
        }

        return $user->is_primary_admin
            || $user->isTenantAdmin()
            || $user->hasPermission('church.settings.edit');
    }
}
