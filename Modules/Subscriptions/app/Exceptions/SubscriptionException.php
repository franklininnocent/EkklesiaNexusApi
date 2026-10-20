<?php

namespace Modules\Subscriptions\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Structured subscription API error (section "API error contract").
 * Messages are user-safe; context never includes secrets or other tenants' data.
 */
class SubscriptionException extends RuntimeException implements Responsable
{
    public const FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE';

    public const ENTITLEMENT_LIMIT_REACHED = 'ENTITLEMENT_LIMIT_REACHED';

    public const ENTITLEMENT_CONFIGURATION_MISSING = 'ENTITLEMENT_CONFIGURATION_MISSING';

    public const PLAN_NOT_AVAILABLE = 'PLAN_NOT_AVAILABLE';

    public const PLAN_VERSION_NOT_ACTIVE = 'PLAN_VERSION_NOT_ACTIVE';

    public const PLAN_CHANGE_NOT_ALLOWED = 'PLAN_CHANGE_NOT_ALLOWED';

    public const PLAN_CHANGE_REQUIRES_CONFIRMATION = 'PLAN_CHANGE_REQUIRES_CONFIRMATION';

    public const ENTERPRISE_CONFIGURATION_REQUIRED = 'ENTERPRISE_CONFIGURATION_REQUIRED';

    public const CATALOG_VALIDATION_FAILED = 'CATALOG_VALIDATION_FAILED';

    public const INVALID_STATE = 'SUBSCRIPTION_INVALID_STATE';

    public const PLAN_HAS_ASSIGNED_CHURCHES = 'PLAN_HAS_ASSIGNED_CHURCHES';

    public const PLAN_EDIT_REQUIRES_CONFIRMATION = 'PLAN_EDIT_REQUIRES_CONFIRMATION';

    public const PLAN_ARCHIVE_REQUIRES_CONFIRMATION = 'PLAN_ARCHIVE_REQUIRES_CONFIRMATION';

    public const BILLING_ACTION_DENIED = 'BILLING_ACTION_DENIED';

    public const BILLING_INVALID_STATE = 'BILLING_INVALID_STATE';

    public const BILLING_VERIFICATION_FAILED = 'BILLING_VERIFICATION_FAILED';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function featureNotAvailable(string $featureCode, ?string $featureName = null, bool $upgradeAvailable = true): self
    {
        $label = $featureName ?: $featureCode;

        return new self(self::FEATURE_NOT_AVAILABLE, "{$label} is not included in your current plan.", 403, [
            'feature' => $featureCode,
            'upgrade_available' => $upgradeAvailable,
        ]);
    }

    public static function entitlementConfigurationMissing(string $featureCode): self
    {
        return new self(
            self::ENTITLEMENT_CONFIGURATION_MISSING,
            'This plan limit is not configured. Contact Ekklesia support.',
            503,
            ['feature' => strtoupper($featureCode)],
        );
    }

    public static function limitReached(string $featureCode, string $unitLabel, int $limit, int $currentUsage, bool $upgradeAvailable = true): self
    {
        return new self(
            self::ENTITLEMENT_LIMIT_REACHED,
            sprintf('Your current plan allows up to %s %s.', number_format($limit), $unitLabel),
            403,
            [
                'feature' => $featureCode,
                'limit' => $limit,
                'current_usage' => $currentUsage,
                'upgrade_available' => $upgradeAvailable,
            ]
        );
    }

    public static function planNotAvailable(string $message = 'This plan is not available.'): self
    {
        return new self(self::PLAN_NOT_AVAILABLE, $message, 422);
    }

    public static function versionNotActive(string $message = 'This plan has no active version.'): self
    {
        return new self(self::PLAN_VERSION_NOT_ACTIVE, $message, 422);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function changeNotAllowed(string $message, array $context = []): self
    {
        return new self(self::PLAN_CHANGE_NOT_ALLOWED, $message, 422, $context);
    }

    /**
     * @param  array<string, mixed>  $impact
     */
    public static function requiresConfirmation(array $impact): self
    {
        return new self(
            self::PLAN_CHANGE_REQUIRES_CONFIRMATION,
            'This change removes features or lowers limits. Review the impact and confirm to continue. No data will be deleted.',
            409,
            ['impact' => $impact]
        );
    }

    public static function enterpriseConfigurationRequired(): self
    {
        return new self(
            self::ENTERPRISE_CONFIGURATION_REQUIRED,
            'Enterprise plans are configured by the Ekklesia team. Please contact us.',
            422
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function catalogInvalid(string $message, array $errors = []): self
    {
        return new self(self::CATALOG_VALIDATION_FAILED, $message, 422, ['errors' => $errors]);
    }

    public static function invalidState(string $message): self
    {
        return new self(self::INVALID_STATE, $message, 409);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function billingDenied(string $reasonCode, string $message, array $context = []): self
    {
        return new self(self::BILLING_ACTION_DENIED, $message, 403, [
            'reason_code' => $reasonCode,
            ...$context,
        ]);
    }

    public static function billingVerificationFailed(string $message): self
    {
        return new self(self::BILLING_VERIFICATION_FAILED, $message, 422);
    }

    /**
     * Plan header fields cannot change while any church is CURRENT or PENDING on the plan.
     */
    public static function planHasAssignedChurches(int $tenantCount): self
    {
        $n = max(0, $tenantCount);
        $noun = $n === 1 ? 'church' : 'churches';
        $message = $n === 0
            ? 'This plan is assigned to one or more churches and cannot be changed. Duplicate it or start a new version so existing churches keep their current terms.'
            : "This plan is assigned to {$n} {$noun} and cannot be changed. Duplicate it or start a new version so existing churches keep their current terms.";

        return new self(self::PLAN_HAS_ASSIGNED_CHURCHES, $message, 409, [
            'tenant_count' => $n,
        ]);
    }

    /**
     * @param  list<array{field: string, reason: string}>  $restrictedFields
     */
    public static function planEditRequiresConfirmation(int $tenantCount, array $restrictedFields): self
    {
        $n = max(0, $tenantCount);
        $noun = $n === 1 ? 'church' : 'churches';

        return new self(
            self::PLAN_EDIT_REQUIRES_CONFIRMATION,
            "This change affects how the plan is offered to churches. {$n} {$noun} already use this plan — confirm to continue. Their price, features, and limits stay on the version they were assigned.",
            409,
            [
                'tenant_count' => $n,
                'restricted_fields' => $restrictedFields,
            ],
        );
    }

    public static function planArchiveRequiresConfirmation(int $tenantCount): self
    {
        $n = max(0, $tenantCount);
        $noun = $n === 1 ? 'church' : 'churches';

        return new self(
            self::PLAN_ARCHIVE_REQUIRES_CONFIRMATION,
            "This plan is assigned to {$n} {$noun}. Archiving removes it from the catalog for new churches. Current churches keep their version. Scheduled moves onto this plan will not complete after archive.",
            409,
            ['tenant_count' => $n],
        );
    }

    public static function planCannotDeleteBecauseAssigned(int $tenantCount = 0): self
    {
        return new self(
            self::PLAN_HAS_ASSIGNED_CHURCHES,
            'This plan cannot be deleted because it is currently associated with one or more churches. Remove the associated churches before deleting this plan.',
            409,
            ['tenant_count' => max(0, $tenantCount)],
        );
    }

    public function toResponse($request): JsonResponse
    {
        return $this->render();
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->context), $this->status);
    }
}
