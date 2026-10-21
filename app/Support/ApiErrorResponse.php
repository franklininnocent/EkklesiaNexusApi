<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\ApplicationAccess\Http\Middleware\AssignRequestId;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Stable, non-sensitive JSON error envelope for API clients.
 *
 * Internal exception text stays in logs. Callers receive a category code,
 * a user-facing message, optional field errors, and the request id.
 */
class ApiErrorResponse
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const CONFLICT = 'CONFLICT';

    public const DUPLICATE = 'DUPLICATE';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const BUSINESS_RULE = 'BUSINESS_RULE';

    public const UNEXPECTED = 'UNEXPECTED';

    public const SESSION_EXPIRED = 'Your session has expired. Please sign in again to continue.';

    public const FORBIDDEN_MESSAGE = 'You do not have permission to perform this action. Please contact your parish administrator if you need access.';

    public const NOT_FOUND_MESSAGE = 'The requested record could not be found. It may have been removed or may no longer be available.';

    public const VALIDATION_SUMMARY = 'Review the highlighted fields and correct the information.';

    public const DUPLICATE_MESSAGE = 'A record with these details already exists. Review the existing record before creating another.';

    public const CONFLICT_MESSAGE = 'This record has been updated by another user. Refresh it to review the latest information before saving your changes.';

    public const FOREIGN_KEY_MESSAGE = 'This record cannot be saved because it conflicts with an existing record.';

    public const RATE_LIMIT_MESSAGE = 'Too many requests. Please wait a moment and try again.';

    public const UNEXPECTED_MESSAGE = "We couldn't complete your request due to an unexpected problem. Please try again shortly. If the problem continues, contact support.";

    public const AMBIGUOUS_TRANSACTION = "We couldn't confirm whether the transaction completed. Check the transaction register before trying again.";

    public const LOGIN_INVALID = 'The email address or password is incorrect. Please check your details and try again.';

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'success' => false,
            'code' => $code,
            'message' => self::publicText($message, self::fallbackForStatus($status)),
            'request_id' => self::requestId(request()),
        ], $extra);

        $payload['message'] = self::publicText((string) ($payload['message'] ?? ''), self::fallbackForStatus($status));
        if (isset($payload['error']) && is_string($payload['error'])) {
            $payload['error'] = self::publicText($payload['error'], $payload['message']);
        }

        return response()->json($payload, $status);
    }

    public static function unauthenticated(): JsonResponse
    {
        return self::make(401, self::UNAUTHENTICATED, self::SESSION_EXPIRED);
    }

    public static function forbidden(?string $message = null): JsonResponse
    {
        $text = self::isGenericForbidden($message) ? self::FORBIDDEN_MESSAGE : (string) $message;

        return self::make(403, self::FORBIDDEN, $text);
    }

    public static function notFound(): JsonResponse
    {
        return self::make(404, self::NOT_FOUND, self::NOT_FOUND_MESSAGE);
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $flat = [];
        foreach ($errors as $messages) {
            foreach ((array) $messages as $message) {
                if (is_string($message) && $message !== '') {
                    $flat[] = $message;
                }
            }
        }

        $summary = count($flat) === 1
            ? $flat[0]
            : self::VALIDATION_SUMMARY;

        return self::make($exception->status, self::VALIDATION_FAILED, $summary, [
            'errors' => $errors,
        ]);
    }

    public static function duplicate(): JsonResponse
    {
        return self::make(409, self::DUPLICATE, self::DUPLICATE_MESSAGE);
    }

    public static function conflict(?string $message = null, string $code = self::CONFLICT): JsonResponse
    {
        $text = ($message !== null && ! self::isUnsafe($message)) ? $message : self::CONFLICT_MESSAGE;

        return self::make(409, $code, $text);
    }

    public static function rateLimited(?int $retryAfter = null): JsonResponse
    {
        $extra = [];
        if ($retryAfter !== null) {
            $extra['retry_after_seconds'] = $retryAfter;
        }

        $response = self::make(429, self::RATE_LIMITED, self::RATE_LIMIT_MESSAGE, $extra);
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    public static function unexpected(): JsonResponse
    {
        return self::make(500, self::UNEXPECTED, self::UNEXPECTED_MESSAGE);
    }

    /**
     * Bubble exceptions the global renderer already knows how to present.
     */
    public static function rethrowExpected(Throwable $exception): void
    {
        if (
            $exception instanceof Responsable
            || $exception instanceof HttpExceptionInterface
            || $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof UniqueConstraintViolationException
            || $exception instanceof QueryException
        ) {
            throw $exception;
        }
    }

    public static function publicMessage(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());
        if ($message === '' || self::isUnsafe($message)) {
            return $fallback;
        }

        return $message;
    }

    public static function isUnsafe(string $message): bool
    {
        $normalized = strtolower($message);

        foreach ([
            'sqlstate',
            'sql syntax',
            'syntax error',
            'duplicate key',
            'violates',
            'constraint',
            'stack trace',
            'stacktrace',
            '#0 ',
            'vendor/',
            'illuminate\\',
            'pdoexception',
            'queryexception',
            '.php',
            '/var/',
            'allowed memory',
            'maximum execution',
            'undefined array',
            'undefined variable',
            'trying to get',
            'trying to access',
            'call to a member',
            'call to undefined',
            'must be of type',
            'token generation failed',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return $state === '23505' || ($state === '23000' && str_contains(strtolower($exception->getMessage()), 'duplicate'));
    }

    public static function isForeignKeyViolation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($state === '23503') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'foreign key') || str_contains($message, '1452');
    }

    public static function requestId(?Request $request = null): string
    {
        $request ??= request();
        $existing = $request?->attributes->get(AssignRequestId::ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = $request?->headers->get(AssignRequestId::HEADER);
        if (is_string($header) && $header !== '') {
            return substr($header, 0, 64);
        }

        return (string) str()->uuid();
    }

    public static function wantsApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Replace leaked technical text inside an already-built JSON error body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $payload, int $status): array
    {
        foreach (['message', 'error'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                continue;
            }
            if (self::isUnsafe($payload[$key])) {
                $payload[$key] = self::fallbackForStatus($status);
                $payload['code'] = $payload['code'] ?? ($status >= 500 ? self::UNEXPECTED : self::BUSINESS_RULE);
            }
        }

        if ($status >= 400 && ! isset($payload['request_id'])) {
            $payload['request_id'] = self::requestId(request());
        }

        if ($status >= 400 && ! array_key_exists('success', $payload)) {
            $payload['success'] = false;
        }

        return $payload;
    }

    public static function fallbackForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::SESSION_EXPIRED,
            $status === 403 => self::FORBIDDEN_MESSAGE,
            $status === 404 => self::NOT_FOUND_MESSAGE,
            $status === 409 => self::CONFLICT_MESSAGE,
            $status === 422 => self::VALIDATION_SUMMARY,
            $status === 429 => self::RATE_LIMIT_MESSAGE,
            $status >= 500 => self::UNEXPECTED_MESSAGE,
            default => self::UNEXPECTED_MESSAGE,
        };
    }

    private static function publicText(string $message, string $fallback): string
    {
        $message = trim($message);

        return ($message === '' || self::isUnsafe($message)) ? $fallback : $message;
    }

    private static function isGenericForbidden(?string $message): bool
    {
        if ($message === null || trim($message) === '') {
            return true;
        }

        $normalized = strtolower(trim($message));

        return in_array($normalized, [
            'this action is unauthorized.',
            'this action is unauthorized',
            'forbidden',
            'forbidden.',
            'unauthorized',
            'access denied',
            'access denied.',
        ], true) || self::isUnsafe($message);
    }
}
