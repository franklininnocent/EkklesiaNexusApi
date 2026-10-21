<?php

namespace Modules\Donations\Exceptions;

use App\Support\ApiErrorResponse;
use RuntimeException;

/**
 * Expected donation workflow failure with a stable code and a user-safe message.
 * Ledger math and idempotency replay stay in their existing services.
 */
class DonationDomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = ApiErrorResponse::BUSINESS_RULE,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function ambiguousOutcome(): self
    {
        return new self(ApiErrorResponse::AMBIGUOUS_TRANSACTION, 'OUTCOME_UNKNOWN', 409);
    }
}
