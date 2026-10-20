<?php

namespace Modules\ApplicationAccess\Services;

use App\Support\OAuthAccessTokenId;
use App\Events\Auth\LoginFailed;
use App\Events\OAuth\AccessTokenCreated;
use App\Events\OAuth\AccessTokenRevoked;
use App\Events\OAuth\AccessTokenRotated;
use App\Events\OAuth\AllUserTokensRevoked;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Contracts\GeoIpReader;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessIdentityResolver;
use Modules\ApplicationAccess\Support\IdentifierMasker;
use Modules\ApplicationAccess\Support\IpAddressClassifier;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\TenantContext;

class ApplicationAccessSessionLifecycleService
{
    public function __construct(
        private readonly ApplicationAccessSessionRepository $sessions,
        private readonly ApplicationAccessRecorder $recorder,
        private readonly ApplicationAccessIdentityResolver $identityResolver,
        private readonly IpAddressClassifier $ipClassifier,
        private readonly GeoIpReader $geoIpReader,
        private readonly ApplicationSecuritySignalService $signals,
    ) {}

    public function handleAccessTokenCreated(AccessTokenCreated $event): void
    {
        $user = User::query()->with('role')->find($event->userId);
        if (! $user) {
            return;
        }

        $previousSessionId = null;
        if ($event->previousAccessTokenId) {
            $previous = $this->sessions->findByOAuthTokenId($event->previousAccessTokenId);
            $previousSessionId = $previous?->id;
        }

        $now = now();
        $ipMeta = $this->resolveRequestIpMeta();
        $geo = $this->geoIpReader->lookup(
            (string) ($ipMeta['ip_address'] ?? ''),
            (string) $ipMeta['ip_class']
        );

        $session = $this->sessions->create([
            'oauth_access_token_id' => OAuthAccessTokenId::normalize($event->accessTokenId),
            'previous_session_id' => $previousSessionId,
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role_id' => $user->role_id,
            'support_session_id' => $this->resolveSupportSessionId(),
            'identity_type' => $this->identityResolver->resolveIdentityType($user),
            'access_context' => $this->identityResolver->resolveAccessContext($user, $event->authContext),
            'authentication_status' => 'authenticated',
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => $now,
            'last_activity_at' => $now,
            'ip_address' => $ipMeta['ip_address'],
            'ip_version' => $ipMeta['ip_version'],
            'ip_class' => $ipMeta['ip_class'],
            'country' => $geo['country'],
            'region' => $geo['region'],
            'city' => $geo['city'],
            'latitude' => $geo['latitude'],
            'longitude' => $geo['longitude'],
            'timezone' => $geo['timezone'],
            'geo_source' => $geo['geo_source'],
            'geo_status' => $geo['geo_status'],
            'user_agent' => $this->truncateUserAgent($this->currentRequest()?->userAgent()),
        ]);

        if (in_array($event->authContext, ['login', 'register'], true)) {
            $this->recorder->recordSecurityEvent([
                'id' => (string) Str::uuid(),
                'event_type' => 'LOGIN_SUCCESS',
                'severity' => 'LOW',
                'actor_user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'access_session_id' => $session->id,
                'source_ip' => $ipMeta['ip_address'],
                'authorization_result' => 'allowed',
                'reason_code' => $event->authContext,
                'request_id' => $this->requestId(),
                'detected_at' => $now,
                'metadata' => ['auth_context' => $event->authContext],
            ]);
        }

        $this->recorder->recordAccessEvent([
            'id' => (string) Str::uuid(),
            'access_session_id' => $session->id,
            'user_id' => $user->id,
            'ip_address' => $ipMeta['ip_address'],
            'event_type' => $event->authContext === 'refresh' ? 'SESSION_RENEWED' : 'SESSION_CREATED',
            'action' => $event->authContext === 'refresh' ? 'RENEW' : 'LOGIN',
            'tenant_id' => $user->tenant_id,
            'support_session_id' => $this->resolveSupportSessionId(),
            'request_id' => $this->requestId(),
            'occurred_at' => $now,
            'authorization_result' => 'allowed',
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);
    }

    public function handleAccessTokenRotated(AccessTokenRotated $event): void
    {
        $session = $this->sessions->findByOAuthTokenId($event->previousAccessTokenId);
        if (! $session) {
            return;
        }

        $this->sessions->endSession(
            $session,
            ApplicationAccessEnums::SESSION_ENDED,
            'rotated'
        );
    }

    public function handleAccessTokenRevoked(AccessTokenRevoked $event): void
    {
        $session = $this->sessions->findByOAuthTokenId($event->accessTokenId);
        if (! $session) {
            return;
        }

        $this->sessions->endSession(
            $session,
            ApplicationAccessEnums::SESSION_REVOKED,
            'revoked'
        );
    }

    public function handleAllUserTokensRevoked(AllUserTokensRevoked $event): void
    {
        ApplicationAccessSession::query()
            ->where('user_id', $event->userId)
            ->whereNull('ended_at')
            ->each(function (ApplicationAccessSession $session): void {
                $this->sessions->endSession(
                    $session,
                    ApplicationAccessEnums::SESSION_ENDED,
                    'logout'
                );
            });
    }

    public function handleLoginFailed(LoginFailed $event): void
    {
        $ipMeta = $this->resolveRequestIpMeta();

        $this->recorder->recordSecurityEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'LOGIN_FAILURE',
            'severity' => 'MEDIUM',
            'source_ip' => $ipMeta['ip_address'],
            'authorization_result' => 'denied',
            'reason_code' => $event->reasonCode,
            'request_id' => $this->requestId(),
            'detected_at' => now(),
            'metadata' => [
                'masked_identifier' => IdentifierMasker::maskEmail($event->emailAttempt),
            ],
        ]);

        $this->signals->recordRepeatedLoginFailures((string) ($ipMeta['ip_address'] ?? ''));
    }

    /**
     * @return array{ip_address: ?string, ip_version: ?int, ip_class: string}
     */
    private function resolveRequestIpMeta(): array
    {
        $request = $this->currentRequest();
        $ip = $request?->ip();

        return $this->ipClassifier->classify($ip);
    }

    private function resolveSupportSessionId(): ?string
    {
        if (! app()->bound(TenantContext::class)) {
            return null;
        }

        $context = app(TenantContext::class);

        return $context->isSupportSession() ? $context->supportSessionId() : null;
    }

    private function requestId(): ?string
    {
        $request = $this->currentRequest();

        return $request?->attributes->get(AssignRequestId::ATTRIBUTE)
            ?? $request?->header(AssignRequestId::HEADER);
    }

    private function currentRequest(): ?Request
    {
        return app()->runningInConsole() ? null : request();
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        return strlen($userAgent) > 512 ? substr($userAgent, 0, 512) : $userAgent;
    }
}
