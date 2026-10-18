<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;

/**
 * Strict Quick Collect contract for collection_mode=mandatory_dues.
 */
class PaymentMandatoryDuesValidator
{
    public const MODE = 'mandatory_dues';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertValid(int $tenantId, array $payload): void
    {
        $familyId = (string) ($payload['family_id'] ?? '');
        if ($familyId === '') {
            throw new \RuntimeException('Family is required for mandatory due collection.');
        }

        $paymentAmount = MoneyMath::normalize($payload['amount'] ?? 0);
        $allocations = $payload['allocations'] ?? [];
        if ($allocations === []) {
            throw new \RuntimeException('At least one mandatory due allocation is required.');
        }

        $seenIds = [];
        $allocationSum = '0.00';

        foreach ($allocations as $allocation) {
            $type = (string) ($allocation['allocatable_type'] ?? '');
            if ($type !== 'due') {
                throw new \RuntimeException('Mandatory due collection only allows due allocations.');
            }

            $dueId = (string) ($allocation['allocatable_id'] ?? '');
            if ($dueId === '') {
                throw new \RuntimeException('Each mandatory due allocation must include a due id.');
            }

            if (isset($seenIds[$dueId])) {
                throw new \RuntimeException('Duplicate mandatory due allocation in the same payment is not allowed.');
            }
            $seenIds[$dueId] = true;

            $requested = MoneyMath::normalize($allocation['amount'] ?? 0);
            if (! MoneyMath::isPositive($requested)) {
                throw new \RuntimeException('Each mandatory due allocation amount must be greater than zero.');
            }
            $allocationSum = MoneyMath::add($allocationSum, $requested);
        }

        if (! MoneyMath::equals($allocationSum, $paymentAmount)) {
            throw new \RuntimeException('Payment amount must equal the sum of mandatory due allocations.');
        }

        $sortedIds = array_keys($seenIds);
        sort($sortedIds, SORT_STRING);

        foreach ($sortedIds as $dueId) {
            $due = ContributionDue::forTenant($tenantId)->whereKey($dueId)->lockForUpdate()->first();
            if (! $due) {
                throw new \RuntimeException('Invalid due allocation target for tenant.');
            }

            if ((string) $due->family_id !== $familyId) {
                throw new \RuntimeException('This contribution does not belong to the selected family.');
            }

            if (in_array($due->status, ['waived', 'cancelled'], true)) {
                throw new \RuntimeException('Cannot allocate a payment to a waived or cancelled contribution.');
            }

            $requested = $this->requestedAmountForDue($allocations, $dueId);
            $outstanding = ContributionBalance::outstandingString($due);

            if (! MoneyMath::isPositive($outstanding)) {
                throw new \RuntimeException('This contribution has no remaining balance to collect.');
            }

            if (MoneyMath::compare($requested, $outstanding) > 0) {
                throw new \RuntimeException(
                    'Contribution balance changed or allocation exceeds remaining balance. Refresh and try again.'
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     */
    private function requestedAmountForDue(array $allocations, string $dueId): string
    {
        foreach ($allocations as $allocation) {
            if ((string) ($allocation['allocatable_id'] ?? '') === $dueId) {
                return MoneyMath::normalize($allocation['amount'] ?? 0);
            }
        }

        return '0.00';
    }
}
