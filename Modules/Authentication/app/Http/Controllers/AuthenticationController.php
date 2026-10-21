<?php

namespace Modules\Authentication\Http\Controllers;

use App\Support\ApiErrorResponse;

use App\Events\Auth\LoginFailed;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Http\Requests\UploadSelfProfileImageRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\UserProfileImageService;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\Media\ImageMediaException;

class AuthenticationController extends Controller
{
    public function __construct(
        protected TokenService $tokenService,
        protected UserProfileImageService $userProfileImageService,
    ) {}

    /**
     * Public self-registration is disabled.
     * Parish and platform accounts are created by an authenticated administrator.
     */
    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Self-registration is not available. Ask your parish administrator to create an account.',
        ], 403);
    }

    /**
     * Login user and return token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        // Load user with role relationship
        $user = User::with('role')->where('email', $request->email)->first();

        // Check if user exists
        if (! $user) {
            event(new LoginFailed($request->email, 'invalid_credentials'));
            throw ValidationException::withMessages([
                'email' => [\App\Support\ApiErrorResponse::LOGIN_INVALID],
            ]);
        }

        // Check password
        if (! Hash::check($request->password, $user->password)) {
            event(new LoginFailed($request->email, 'invalid_credentials'));
            throw ValidationException::withMessages([
                'email' => [\App\Support\ApiErrorResponse::LOGIN_INVALID],
            ]);
        }

        // Check if user is active
        if ($user->active !== 1) {
            event(new LoginFailed($request->email, 'account_deactivated'));
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Please contact support.'],
            ]);
        }

        // Check if user role is active
        if ($user->role && $user->role->active !== 1) {
            event(new LoginFailed($request->email, 'role_deactivated'));
            throw ValidationException::withMessages([
                'email' => ['Your role has been deactivated. Please contact support.'],
            ]);
        }

        // Generate OAuth2 tokens with refresh token
        try {
            $tokens = $this->tokenService->createTokens($user, null, [], null, 'login');

            return response()->json([
                'access_token' => $tokens['access_token_string'],
                'refresh_token' => $tokens['refresh_token_string'],
                'expiry_time' => $tokens['access_token']->expires_at->toDateTimeString(),
                'user_id' => $user->id,
                'role_id' => $user->role_id,
                'token_type' => 'Bearer',
                'force_password_change' => (bool) ($user->force_password_change ?? false),
                'message' => 'Login successful',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return \App\Support\ApiErrorResponse::unexpected();
        }
    }

    /**
     * Refresh the user's access token using refresh token.
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        try {
            // Refresh tokens using the refresh token
            $tokens = $this->tokenService->refreshTokens($request->refresh_token);

            return response()->json([
                'access_token' => $tokens['access_token_string'],
                'refresh_token' => $tokens['refresh_token_string'],
                'expiry_time' => $tokens['access_token']->expires_at->toDateTimeString(),
                'user_id' => $tokens['access_token']->user_id,
                'role_id' => User::find($tokens['access_token']->user_id)->role_id,
                'token_type' => 'Bearer',
                'message' => 'Token refreshed successfully',
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            return \App\Support\ApiErrorResponse::unauthenticated();
        }
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user();
        if ($user) {
            // Revoke all user tokens (access and refresh tokens)
            $this->tokenService->revokeAllTokens($user->id);
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get authenticated user details.
     */
    public function user(Request $request)
    {
        $user = Auth::guard('api')->user();

        if ($user) {
            // Load role relationship
            $user->load('role');

            // Prepare user response with role information
            $userResponse = $user->toArray();
            $userResponse['role_name'] = $user->role ? $user->role->name : null;
            $userResponse['role_level'] = $user->role ? $user->role->level : null;
            $userResponse['is_super_admin'] = $user->isSuperAdmin();
            $userResponse['is_admin'] = $user->isAdmin();

            return response()->json($userResponse);
        }

        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    /**
     * Get logged-in user details (new endpoint).
     * Returns structured user data with role information.
     */
    public function getUser(Request $request)
    {
        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Load relationships: role, roles (multi-role), permissions, tenant with addresses
        $user->load([
            'role',
            'roles.permissions',
            'permissions',
            'tenant',
        ]);

        // Get tenant's country information from addresses
        $tenantCountry = null;
        $tenantCountryId = null;
        if ($user->tenant) {
            // Load tenant addresses with country relationship
            $user->tenant->load(['addresses.country']);

            // Use the tenant's addresses relationship
            $addresses = $user->tenant->addresses()->where('active', 1)->whereNotNull('country_id')->with('country')->get();

            // Try to get country from official address first
            $officialAddress = $addresses->firstWhere('address_type', 'official');
            if ($officialAddress) {
                $country = $officialAddress->getRelation('country');
                if ($country instanceof Country) {
                    $tenantCountry = $country;
                    $tenantCountryId = $officialAddress->country_id;
                }
            }
            // Fallback to primary address
            if (! $tenantCountry && $addresses->isNotEmpty()) {
                $primaryAddress = $addresses->firstWhere('address_type', 'primary');
                if ($primaryAddress) {
                    $country = $primaryAddress->getRelation('country');
                    if ($country instanceof Country) {
                        $tenantCountry = $country;
                        $tenantCountryId = $primaryAddress->country_id;
                    }
                }
            }
            // Fallback to first active address with country
            if (! $tenantCountry && $addresses->isNotEmpty()) {
                $anyAddress = $addresses->first();
                if ($anyAddress) {
                    $country = $anyAddress->getRelation('country');
                    if ($country instanceof Country) {
                        $tenantCountry = $country;
                        $tenantCountryId = $anyAddress->country_id;
                    }
                }
            }
        }

        // Build user response with all relationships
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'user_type' => $user->user_type,
            'is_primary_admin' => $user->is_primary_admin ?? false,
            'tenant_id' => $user->tenant_id,
            'profile_image_full_url' => $user->profile_image_full_url,
            'role_id' => $user->role_id, // Legacy
            'role_name' => $user->role ? $user->role->name : null, // Legacy
            'role_level' => $user->role ? $user->role->level : null, // Legacy
            'active' => $user->active,
            'force_password_change' => (bool) ($user->force_password_change ?? false),
            'password_changed_at' => $user->password_changed_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_admin' => $user->isAdmin(),
            'has_ekklesia_role' => $user->hasEkklesiaRole(),
            // Multi-role support
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions()->values(),
            // Tenant relationship
            'tenant' => $user->tenant ? array_merge($user->tenant->toArray(), [
                // Add country information for phone code lookup
                'country_id' => $tenantCountryId,
                'country' => $tenantCountry ? [
                    'id' => $tenantCountry->id,
                    'name' => $tenantCountry->name,
                    'iso2' => $tenantCountry->iso2,
                    'iso3' => $tenantCountry->iso3,
                    'phone_code' => $tenantCountry->phone_code,
                    'emoji' => $tenantCountry->emoji,
                ] : null,
                'currency' => app(ChurchCurrencyResolver::class)
                    ->forTenantId((int) $user->tenant_id)
                    ?->toArray(),
            ]) : null,
        ];

        return response()->json([
            'success' => true,
            'data' => $userData,
            'message' => 'User details retrieved successfully',
        ]);
    }

    /**
     * Upload or replace the authenticated tenant administrator's own profile image.
     */
    public function uploadSelfProfileImage(UploadSelfProfileImageRequest $request): JsonResponse
    {
        $authUser = $request->user();

        if ($denied = $this->denyUnlessTenantAdminSelfService($authUser)) {
            return $denied;
        }

        try {
            $hadImage = $authUser->profile_image_path !== null;

            $this->userProfileImageService->replaceProfileImage(
                $request->file('profile_image'),
                $authUser
            );

            $authUser->refresh();
            $authUser->load(['roles', 'tenant']);

            Log::info('User profile image updated', [
                'updated_by' => $authUser->id,
                'user_id' => $authUser->id,
                'tenant_id' => $authUser->tenant_id,
                'action' => $hadImage ? 'replaced' : 'uploaded',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile image uploaded successfully.',
                'data' => $authUser,
            ]);
        } catch (ImageMediaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->publicMessage(),
            ], 422);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error uploading self profile image: '.$e->getMessage(), [
                'user_id' => $authUser->id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while uploading the profile image.',
            ], 500);
        }
    }

    /**
     * Remove the authenticated tenant administrator's own profile image.
     */
    public function deleteSelfProfileImage(Request $request): JsonResponse
    {
        $authUser = $request->user();

        if ($denied = $this->denyUnlessTenantAdminSelfService($authUser)) {
            return $denied;
        }

        try {
            if ($authUser->profile_image_path && $authUser->tenant_id) {
                $this->userProfileImageService->deleteProfileImage(
                    $authUser->profile_image_path,
                    (int) $authUser->tenant_id
                );
            }

            $authUser->profile_image_path = null;
            $authUser->save();
            $authUser->load(['roles', 'tenant']);

            Log::info('User profile image removed', [
                'updated_by' => $authUser->id,
                'user_id' => $authUser->id,
                'tenant_id' => $authUser->tenant_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile image removed successfully.',
                'data' => $authUser,
            ]);
        } catch (\Exception $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Error deleting self profile image: '.$e->getMessage(), [
                'user_id' => $authUser->id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while removing the profile image.',
            ], 500);
        }
    }

    private function denyUnlessTenantAdminSelfService(?User $user): ?JsonResponse
    {
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user->loadMissing(['roles', 'role']);

        if (! $user->tenant_id || ! $this->canManageOwnProfileImage($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant administrator access required.',
            ], 403);
        }

        return null;
    }

    private function canManageOwnProfileImage(User $user): bool
    {
        if ($user->is_primary_admin) {
            return true;
        }

        if ($user->isTenantAdmin()) {
            return true;
        }

        $legacyRole = $user->relationLoaded('role') ? $user->role : $user->role()->first();

        return $legacyRole !== null
            && $legacyRole->name === Role::TENANT_ADMINISTRATOR
            && (int) $legacyRole->tenant_id === (int) $user->tenant_id;
    }

    /**
     * View methods (for Nwidart module - optional if you use API only)
     */
    public function index()
    {
        return view('authentication::index');
    }

    public function create()
    {
        return view('authentication::create');
    }

    public function store(Request $request) {}

    public function show($id)
    {
        return view('authentication::show');
    }

    public function edit($id)
    {
        return view('authentication::edit');
    }

    public function update(Request $request, $id) {}

    public function destroy($id) {}
}
