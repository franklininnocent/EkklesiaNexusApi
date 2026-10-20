<?php

namespace Modules\ApplicationAccess\Repositories;

use App\Support\OAuthAccessTokenId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;

class ApplicationAccessSessionRepository
{
    public function findByOAuthTokenId(string $oauthAccessTokenId): ?ApplicationAccessSession
    {
        $tokenId = OAuthAccessTokenId::normalize($oauthAccessTokenId);
        if ($tokenId === null) {
            return null;
        }

        return ApplicationAccessSession::query()
            ->where('oauth_access_token_id', $tokenId)
            ->first();
    }

    public function findById(string $id): ?ApplicationAccessSession
    {
        return ApplicationAccessSession::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ApplicationAccessSession
    {
        if (! isset($attributes['session_reference'])) {
            $attributes['session_reference'] = Str::upper(Str::random(12));
        }

        return ApplicationAccessSession::query()->create($attributes);
    }

    public function touchActivity(ApplicationAccessSession $session): void
    {
        $touchSeconds = (int) config('applicationaccess.session_touch_seconds', 60);
        $cacheKey = 'application_access:session_touch:'.$session->id;

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, $touchSeconds);

        $session->forceFill([
            'last_activity_at' => now(),
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
        ])->save();
    }

    public function endSession(
        ApplicationAccessSession $session,
        string $status,
        string $endReason
    ): ApplicationAccessSession {
        $session->forceFill([
            'status' => $status,
            'ended_at' => now(),
            'end_reason' => $endReason,
        ])->save();

        return $session->refresh();
    }
}
