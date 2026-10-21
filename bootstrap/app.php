<?php

use App\Http\Middleware\ForceJsonOnApi;
use App\Http\Middleware\PassportAuthenticate;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\TenantFileAccessMiddleware;
use App\Support\ApiErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\ApplicationAccess\Http\Middleware\CaptureApplicationAccess;
use Modules\ApplicationAccess\Http\Middleware\EnforceApplicationIpBlocks;
use Modules\ApplicationAccess\Http\Middleware\EnsureApplicationAccessPermission;
use Modules\ApplicationAccess\Support\TrustedProxyConfiguration;
use Modules\Donations\Http\Middleware\EnsureDonationFeatureEnabled;
use Modules\Donations\Http\Middleware\LogDonationSecurityResponse;
use Modules\EcclesiasticalData\Http\Middleware\EnsureEcclesiasticalPermission;
use Modules\Marketing\Http\Middleware\EnsureWebSettingsPermission;
use Modules\MinistriesAssociations\Http\Middleware\EnsureAdminMinistriesPermission;
use Modules\MinistriesAssociations\Http\Middleware\EnsureMinistriesFeatureEnabled;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenantPermission;
use Modules\SupportAccess\Http\Middleware\EnforceSupportSessionMode;
use Modules\SupportAccess\Http\Middleware\EnsureSupportPermission;
use Modules\SupportTickets\Http\Middleware\EnsureParishTicketActor;
use Modules\Tenants\Http\Middleware\ApplyTenantRlsTransaction;
use Modules\Tenants\Http\Middleware\EnforceApiPaginationLimits;
use Modules\Tenants\Http\Middleware\EnsureSubscriptionAccess;
use Modules\Tenants\Http\Middleware\EnsureSubscriptionAccessMode;
use Modules\Tenants\Http\Middleware\LogPlatformSecurityEvents;
use Modules\Tenants\Http\Middleware\ResolveTenantContext;
use Modules\Tenants\Http\Middleware\ThrottleTenantApiRequests;
use Modules\Authentication\Http\Middleware\EnsureForcedPasswordChange;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS for API requests
        $middleware->prepend(HandleCors::class);

        $trustedProxies = TrustedProxyConfiguration::proxies();
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
            );
        }

        // Register auth middleware alias for Passport
        $middleware->alias([
            'auth' => Authenticate::class,
            'passport' => PassportAuthenticate::class,
            'tenant.permission' => EnsureTenantPermission::class,
            'tenant.feature.donations' => EnsureDonationFeatureEnabled::class,
            'donations.security.log' => LogDonationSecurityResponse::class,
            'tenant.feature.ministries' => EnsureMinistriesFeatureEnabled::class,
            'tenant.feature.mass_intentions' => \Modules\MassIntentions\Http\Middleware\EnsureMassIntentionsFeatureEnabled::class,
            'tenant.subscription' => EnsureSubscriptionAccess::class,
            'support.permission' => EnsureSupportPermission::class,
            'support.mode' => EnforceSupportSessionMode::class,
            'support.ticket.parish_actor' => EnsureParishTicketActor::class,
            'ministries.platform.permission' => EnsureAdminMinistriesPermission::class,
            'ecclesiastical.permission' => EnsureEcclesiasticalPermission::class,
            'web_settings.permission' => EnsureWebSettingsPermission::class,
            'api.throttle' => ThrottleTenantApiRequests::class,
            'application_access.permission' => EnsureApplicationAccessPermission::class,
        ]);

        // Configure API middleware group - set default guard to API
        // Order: bindings → Passport identity → TenantContext bind (SSOT for effective tenant)
        $middleware->api(prepend: [
            ForceJsonOnApi::class,
            AssignRequestId::class,
            SubstituteBindings::class,
            PassportAuthenticate::class,
            EnforceApplicationIpBlocks::class,
            ResolveTenantContext::class,
            EnsureForcedPasswordChange::class,
            EnforceApiPaginationLimits::class,
            ThrottleTenantApiRequests::class,
            ApplyTenantRlsTransaction::class,
            EnforceSupportSessionMode::class,
            EnsureSubscriptionAccessMode::class,
            CaptureApplicationAccess::class,
        ]);

        // Add security headers to all responses
        $middleware->append(SecurityHeadersMiddleware::class);

        // Platform security audit trail for auth failures (Phase 10)
        $middleware->append(LogPlatformSecurityEvents::class);

        // Add tenant file access control
        $middleware->append(TenantFileAccessMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::unauthenticated();
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::validation($e);
            }
        });

        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::duplicate();
            }
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! ApiErrorResponse::wantsApi($request)) {
                return null;
            }

            if (ApiErrorResponse::isUniqueViolation($e)) {
                return ApiErrorResponse::duplicate();
            }

            if (ApiErrorResponse::isForeignKeyViolation($e)) {
                return ApiErrorResponse::conflict(ApiErrorResponse::FOREIGN_KEY_MESSAGE);
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::notFound();
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::forbidden($e->getMessage());
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (ApiErrorResponse::wantsApi($request)) {
                return ApiErrorResponse::forbidden($e->getMessage());
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! ApiErrorResponse::wantsApi($request)) {
                return null;
            }

            $retry = $e->getHeaders()['Retry-After'] ?? null;

            return ApiErrorResponse::rateLimited(is_numeric($retry) ? (int) $retry : null);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ApiErrorResponse::wantsApi($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            if ($status >= 500) {
                return null;
            }

            $message = ApiErrorResponse::publicMessage($e, ApiErrorResponse::fallbackForStatus($status));
            $code = match (true) {
                $status === 401 => ApiErrorResponse::UNAUTHENTICATED,
                $status === 403 => ApiErrorResponse::FORBIDDEN,
                $status === 404 => ApiErrorResponse::NOT_FOUND,
                $status === 409 => ApiErrorResponse::CONFLICT,
                $status === 422 => ApiErrorResponse::BUSINESS_RULE,
                $status === 429 => ApiErrorResponse::RATE_LIMITED,
                default => ApiErrorResponse::BUSINESS_RULE,
            };

            return ApiErrorResponse::make($status, $code, $message);
        });

        $exceptions->context(function () {
            $url = request()?->fullUrl();
            $context = [
                'request_id' => ApiErrorResponse::requestId(request()),
            ];
            if (! is_string($url) || $url === '') {
                return $context;
            }

            $redacted = preg_replace('/([?&](?:token|session|password|refresh_token)=)([^&]*)/i', '$1redacted', $url);
            $context['url'] = $redacted;

            return $context;
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return $response;
            }

            $origin = $request->headers->get('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);
            $status = $response->getStatusCode();

            if ($status >= 500) {
                // API clients never receive a framework trace, even when APP_DEBUG is on.
                // The exception itself is still reported to the application log with the request id.
                Log::error('API unexpected failure', [
                    'request_id' => ApiErrorResponse::requestId($request),
                    'exception' => $exception::class,
                    'route' => $request->method().' '.$request->path(),
                ]);
                $response = ApiErrorResponse::unexpected();
                $response->setStatusCode($status);
            } elseif ($response instanceof JsonResponse && $status >= 400) {
                $data = $response->getData(true);
                if (is_array($data)) {
                    $response->setData(ApiErrorResponse::sanitizePayload($data, $status));
                }
            }

            $response->headers->set('X-Request-Id', ApiErrorResponse::requestId($request));

            if ($origin && in_array($origin, $allowedOrigins, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Vary', 'Origin');
            }

            return $response;
        });
    })->create();
