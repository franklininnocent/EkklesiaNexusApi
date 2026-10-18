<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;

/**
 * Strict Quick Collect contract for collection_mode=project_installments.
 */
class PaymentProjectInstallmentsValidator
{
    public const MODE = 'project_installments';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertValid(int $tenantId, array $payload): void
    {
        $familyId = (string) ($payload['family_id'] ?? '');
        if ($familyId === '') {
            throw new \RuntimeException('Family is required for project installment collection.');
        }

        $paymentAmount = MoneyMath::normalize($payload['amount'] ?? 0);
        $allocations = $payload['allocations'] ?? [];
        if ($allocations === []) {
            throw new \RuntimeException('At least one installment allocation is required.');
        }

        $seenIds = [];
        $allocationSum = '0.00';
        $projectId = null;

        foreach ($allocations as $allocation) {
            $type = (string) ($allocation['allocatable_type'] ?? '');
            if ($type !== 'project_installment') {
                throw new \RuntimeException('Project installment collection only allows project_installment allocations.');
            }

            $installmentId = (string) ($allocation['allocatable_id'] ?? '');
            if ($installmentId === '') {
                throw new \RuntimeException('Each installment allocation must include an installment id.');
            }

            if (isset($seenIds[$installmentId])) {
                throw new \RuntimeException('Duplicate installment allocation in the same payment is not allowed.');
            }
            $seenIds[$installmentId] = true;

            $requested = MoneyMath::normalize($allocation['amount'] ?? 0);
            if (! MoneyMath::isPositive($requested)) {
                throw new \RuntimeException('Each installment allocation amount must be greater than zero.');
            }
            $allocationSum = MoneyMath::add($allocationSum, $requested);
        }

        if (! MoneyMath::equals($allocationSum, $paymentAmount)) {
            throw new \RuntimeException('Payment amount must equal the sum of installment allocations.');
        }

        $sortedIds = array_keys($seenIds);
        sort($sortedIds, SORT_STRING);

        foreach ($sortedIds as $installmentId) {
            $installment = ProjectInstallmentDue::forTenant($tenantId)
                ->whereKey($installmentId)
                ->lockForUpdate()
                ->first();

            if (! $installment) {
                throw new \RuntimeException('Invalid project installment allocation target for tenant.');
            }

            if ((string) $installment->family_id !== $familyId) {
                throw new \RuntimeException('This installment does not belong to the selected family.');
            }

            if (in_array($installment->status, ['waived', 'cancelled'], true)) {
                throw new \RuntimeException('Cannot allocate a payment to a waived or cancelled installment.');
            }

            if ($projectId === null) {
                $projectId = (string) $installment->project_id;
            } elseif ($projectId !== (string) $installment->project_id) {
                throw new \RuntimeException('All installments in one payment must belong to the same project.');
            }

            $requested = $this->requestedAmountForInstallment($allocations, $installmentId);
            $outstanding = ContributionBalance::outstandingString($installment);

            if (! MoneyMath::isPositive($outstanding)) {
                throw new \RuntimeException('This installment has no remaining balance to collect.');
            }

            if (MoneyMath::compare($requested, $outstanding) > 0) {
                throw new \RuntimeException(
                    'Installment balance changed or allocation exceeds remaining balance. Refresh and try again.'
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $allocations
     */
    private function requestedAmountForInstallment(array $allocations, string $installmentId): string
    {
        foreach ($allocations as $allocation) {
            if ((string) ($allocation['allocatable_id'] ?? '') === $installmentId) {
                return MoneyMath::normalize($allocation['amount'] ?? 0);
            }
        }

        return '0.00';
    }
}
