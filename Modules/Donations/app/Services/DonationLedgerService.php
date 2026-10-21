<?php

namespace Modules\Donations\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Donations\Jobs\SendPaymentReceiptJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationApproval;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\Donor;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\PaymentReversal;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Notifications\Contracts\NotificationPublisherContract;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class DonationLedgerService
{
    /** @var array<int, string> */
    private const UNWIND_ORDER = [
        'due',
        'project_installment',
        'donation',
        'project',
        'fund',
        'plan',
        'advance',
    ];

    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationProjectService $projectService,
        private readonly ProjectInstallmentDueService $projectInstallmentService,
        private readonly DonationEntryBalanceService $donationBalanceService,
        private readonly DonationReceiptService $receiptService,
        private readonly DonationNumberSequenceService $sequences,
        private readonly DonationSecurityEventService $securityEvents,
        private readonly PaymentProjectInstallmentsValidator $projectInstallmentsValidator,
        private readonly PaymentMandatoryDuesValidator $mandatoryDuesValidator
    ) {}

    public function createPayment(int $tenantId, int $userId, array $payload): DonationPayment
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): DonationPayment {
            $this->assertFamilyInTenant($tenantId, $payload['family_id'] ?? null);
            $this->assertDonorInTenant($tenantId, $payload['donor_id'] ?? null);

            $amount = MoneyMath::normalize($payload['amount'] ?? 0);
            if (! MoneyMath::isPositive($amount)) {
                throw new \RuntimeException('Payment amount must be greater than zero.');
            }

            $collectionMode = (string) ($payload['collection_mode'] ?? '');
            $strictCollectionMode = in_array($collectionMode, [
                PaymentProjectInstallmentsValidator::MODE,
                PaymentMandatoryDuesValidator::MODE,
            ], true) ? $collectionMode : null;

            if ($strictCollectionMode === PaymentProjectInstallmentsValidator::MODE) {
                $this->projectInstallmentsValidator->assertValid($tenantId, $payload);
            } elseif ($strictCollectionMode === PaymentMandatoryDuesValidator::MODE) {
                $this->mandatoryDuesValidator->assertValid($tenantId, $payload);
            }

            $reference = $this->normalizeReference($payload['gateway_reference'] ?? null);
            $this->assertUniqueReference($tenantId, $reference);

            $payment = DonationPayment::create([
                'tenant_id' => $tenantId,
                'family_id' => $payload['family_id'] ?? null,
                'donor_id' => $payload['donor_id'] ?? null,
                'payment_batch_id' => $payload['payment_batch_id'] ?? null,
                'payment_number' => $this->sequences->nextPaymentNumber($tenantId),
                'payer_name' => $payload['payer_name'],
                'payer_email' => $payload['payer_email'] ?? null,
                'payer_phone' => $payload['payer_phone'] ?? null,
                'payment_date' => $payload['payment_date'],
                'amount' => $amount,
                'refunded_amount' => '0.00',
                'currency' => $this->resolvePaymentCurrency($tenantId),
                'method' => $payload['method'],
                'gateway_reference' => $reference,
                'status' => 'succeeded',
                'source_type' => $payload['source_type'] ?? 'general',
                'is_anonymous' => (bool) ($payload['is_anonymous'] ?? false),
                'notes' => $payload['notes'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $allocatedTotal = '0.00';
            $allocationRows = $payload['allocations'] ?? [];
            if ($strictCollectionMode !== null) {
                usort($allocationRows, static function (array $a, array $b): int {
                    return strcmp((string) ($a['allocatable_id'] ?? ''), (string) ($b['allocatable_id'] ?? ''));
                });
            }

            foreach ($allocationRows as $allocation) {
                $requested = MoneyMath::normalize($allocation['amount'] ?? 0);
                if (! MoneyMath::isPositive($requested)) {
                    continue;
                }

                $applied = $this->persistAllocation(
                    $tenantId,
                    $userId,
                    $payment,
                    (string) $allocation['allocatable_type'],
                    (string) ($allocation['allocatable_id'] ?? $payment->id),
                    $requested,
                    $allocation['notes'] ?? null,
                    $strictCollectionMode
                );
                $allocatedTotal = MoneyMath::add($allocatedTotal, $applied);
            }

            if (MoneyMath::compare($allocatedTotal, $amount) > 0) {
                throw new \RuntimeException('Allocated amount cannot exceed payment amount.');
            }

            if ($strictCollectionMode === null && MoneyMath::compare($amount, $allocatedTotal) > 0) {
                $remainder = MoneyMath::subtract($amount, $allocatedTotal);
                $this->persistAllocation(
                    $tenantId,
                    $userId,
                    $payment,
                    'advance',
                    $payment->id,
                    $remainder,
                    'Auto-created advance balance',
                    false
                );
            }

            $receipt = $this->receiptService->createReceipt($tenantId, $userId, $payment);

            $this->auditService->log(
                $tenantId,
                'payment.created',
                'payment',
                $payment->id,
                null,
                $payment->fresh()->toArray(),
                ['receipt_id' => $receipt->id]
            );

            if (! empty($payment->payer_email)) {
                SendPaymentReceiptJob::dispatch($tenantId, $payment->id);
            }

            return $payment->load(['allocations', 'receipt', 'receipts']);
        });
    }

    public function requestRefund(int $tenantId, int $userId, string $paymentId, array $payload): DonationRefund
    {
        return DB::transaction(function () use ($tenantId, $userId, $paymentId, $payload): DonationRefund {
            $payment = $this->lockPayment($tenantId, $paymentId);

            if (in_array($payment->status, ['reversed', 'refunded'], true)) {
                throw new \RuntimeException('Refund is not allowed for reversed/refunded payments.');
            }

            $requested = MoneyMath::normalize($payload['amount'] ?? 0);
            $remaining = $this->refundableRemaining($payment);
            if (MoneyMath::compare($requested, $remaining) > 0) {
                throw new \RuntimeException('Refund amount cannot exceed the remaining refundable amount.');
            }

            $approval = DonationApproval::create([
                'tenant_id' => $tenantId,
                'target_type' => 'payment',
                'target_id' => $payment->id,
                'action' => 'refund',
                'status' => 'pending',
                'reason' => $payload['reason'] ?? null,
                'requested_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $refund = DonationRefund::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'approval_id' => $approval->id,
                'amount' => $requested,
                'refund_date' => $payload['refund_date'],
                'status' => 'pending',
                'reason' => $payload['reason'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->auditService->log(
                $tenantId,
                'refund.requested',
                'refund',
                $refund->id,
                null,
                $refund->toArray(),
                ['payment_id' => $payment->id, 'approval_id' => $approval->id]
            );

            $this->notifyRefundRequested($tenantId, $userId, $approval, $payment, $requested);

            return $refund->load(['approval', 'payment']);
        });
    }

    public function completeApprovedRefund(int $tenantId, int $userId, DonationRefund $refund): DonationRefund
    {
        return DB::transaction(function () use ($tenantId, $userId, $refund): DonationRefund {
            $payment = $this->lockPayment($tenantId, $refund->payment_id);
            if (in_array($payment->status, ['reversed', 'refunded'], true)) {
                throw new \RuntimeException('Refund is not allowed for reversed/refunded payments.');
            }

            $amount = MoneyMath::normalize($refund->amount);
            $remaining = $this->refundableRemaining($payment);
            if (MoneyMath::compare($amount, $remaining) > 0) {
                throw new \RuntimeException('Refund amount cannot exceed the remaining refundable amount.');
            }

            $this->unwindAmount($tenantId, $userId, $payment, $amount);

            $payment->refunded_amount = MoneyMath::add($payment->refunded_amount ?? 0, $amount);
            if (! MoneyMath::isPositive($this->refundableRemaining($payment))) {
                $payment->status = 'refunded';
                $currentReceipt = $this->receiptService->currentReceipt($payment);
                if ($currentReceipt) {
                    $this->receiptService->voidReceipt($tenantId, $userId, $currentReceipt, 'Payment fully refunded.');
                }
            }
            $payment->updated_by = $userId;
            $payment->save();

            $refund->status = 'completed';
            $refund->updated_by = $userId;
            $refund->save();

            $this->auditService->log(
                $tenantId,
                'refund.completed',
                'refund',
                $refund->id,
                null,
                $refund->toArray(),
                ['payment_id' => $payment->id]
            );

            return $refund->fresh(['approval', 'payment']);
        });
    }

    public function reversePayment(int $tenantId, int $userId, string $paymentId, string $reason): DonationPayment
    {
        return DB::transaction(function () use ($tenantId, $userId, $paymentId, $reason): DonationPayment {
            $payment = $this->lockPayment($tenantId, $paymentId);
            $oldValues = $payment->toArray();

            if (in_array($payment->status, ['reversed', 'refunded'], true)) {
                throw new \RuntimeException('Payment is already reversed or refunded.');
            }

            $this->unwindAmount($tenantId, $userId, $payment, MoneyMath::subtract($payment->amount, $payment->refunded_amount ?? 0));

            $payment->status = 'reversed';
            $payment->notes = trim(($payment->notes ?? '').' Reversal reason: '.$reason);
            $payment->updated_by = $userId;
            $payment->save();

            $currentReceipt = $this->receiptService->currentReceipt($payment);
            if ($currentReceipt) {
                $this->receiptService->voidReceipt($tenantId, $userId, $currentReceipt, $reason);
            }

            PaymentReversal::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'reason' => $reason,
                'amount' => $payment->amount,
                'reversed_at' => now()->toDateString(),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->auditService->log(
                $tenantId,
                'payment.reversed',
                'payment',
                $payment->id,
                $oldValues,
                $payment->fresh()->toArray(),
                ['reason' => $reason]
            );

            return $payment->fresh(['allocations', 'receipts']);
        });
    }

    public function refundableRemaining(DonationPayment $payment): string
    {
        return MoneyMath::floorAtZero(MoneyMath::subtract($payment->amount, $payment->refunded_amount ?? 0));
    }

    private function lockPayment(int $tenantId, string $paymentId): DonationPayment
    {
        $payment = DonationPayment::forTenant($tenantId)->where('id', $paymentId)->lockForUpdate()->first();
        if ($payment) {
            return $payment->load('allocations');
        }

        $foreign = DonationPayment::query()->where('id', $paymentId)->first();
        if ($foreign) {
            $this->securityEvents->record('idor_denied', $tenantId, 'payment', $paymentId, 404, [
                'foreign_tenant_id' => $foreign->tenant_id,
            ]);
        }

        throw new ModelNotFoundException;
    }

    private function persistAllocation(
        int $tenantId,
        int $userId,
        DonationPayment $payment,
        string $type,
        string $allocatableId,
        string $amount,
        ?string $notes,
        ?string $strictCollectionMode = null
    ): string {
        if ($type !== 'advance' && ! $this->isValidAllocatable($tenantId, $type, $allocatableId)) {
            $this->securityEvents->record('cross_tenant_allocation', $tenantId, $type, $allocatableId, 422);
            throw new \RuntimeException("Invalid {$type} allocation target for tenant.");
        }

        $applied = $amount;
        $advanceRemainder = '0.00';

        if ($type === 'due') {
            $due = ContributionDue::forTenant($tenantId)->whereKey($allocatableId)->lockForUpdate()->first();
            if (! $due) {
                throw new \RuntimeException('Invalid due allocation target for tenant.');
            }
            $this->assertAllocatableFamily($payment, $due->family_id, $type, $allocatableId);
            if (in_array($due->status, ['waived', 'cancelled'], true)) {
                throw new \RuntimeException('Cannot allocate a payment to a waived or cancelled contribution.');
            }
            $outstanding = ContributionBalance::outstandingString($due);
            if (! MoneyMath::isPositive($outstanding)) {
                throw new \RuntimeException('This contribution has no remaining balance to collect.');
            }
            if ($strictCollectionMode === PaymentMandatoryDuesValidator::MODE) {
                if (MoneyMath::compare($amount, $outstanding) > 0) {
                    throw new \RuntimeException(
                        'Contribution balance changed or allocation exceeds remaining balance. Refresh and try again.'
                    );
                }
                $applied = $amount;
                $advanceRemainder = '0.00';
            } else {
                $applied = MoneyMath::min($amount, $outstanding);
                $advanceRemainder = MoneyMath::subtract($amount, $applied);
            }
            if (MoneyMath::isPositive($applied)) {
                ContributionBalance::applyPaid($due, $applied);
                $due->status = ContributionBalance::statusFromPaid($due);
                $due->updated_by = $userId;
                $due->save();
            }
        } elseif ($type === 'project_installment') {
            $installment = ProjectInstallmentDue::forTenant($tenantId)->whereKey($allocatableId)->lockForUpdate()->first();
            if (! $installment) {
                throw new \RuntimeException('Invalid project installment allocation target for tenant.');
            }
            $this->assertAllocatableFamily($payment, $installment->family_id, $type, $allocatableId);
            if (in_array($installment->status, ['waived', 'cancelled'], true)) {
                throw new \RuntimeException('Cannot allocate a payment to a waived or cancelled installment.');
            }
            $outstanding = ContributionBalance::outstandingString($installment);
            if (! MoneyMath::isPositive($outstanding)) {
                throw new \RuntimeException('This installment has no remaining balance to collect.');
            }
            if ($strictCollectionMode === PaymentProjectInstallmentsValidator::MODE) {
                if (MoneyMath::compare($amount, $outstanding) > 0) {
                    throw new \RuntimeException(
                        'Installment balance changed or allocation exceeds remaining balance. Refresh and try again.'
                    );
                }
                $applied = $amount;
                $advanceRemainder = '0.00';
            } else {
                $applied = MoneyMath::min($amount, $outstanding);
                $advanceRemainder = MoneyMath::subtract($amount, $applied);
            }
            if (MoneyMath::isPositive($applied)) {
                $this->projectInstallmentService->applyPayment($tenantId, $userId, $installment, $applied);
            }
        } elseif ($type === 'donation') {
            $donation = Donation::forTenant($tenantId)->find($allocatableId);
            if (! $donation) {
                throw new \RuntimeException('Invalid donation allocation target for tenant.');
            }
            $pledged = MoneyMath::normalize($donation->pledged_amount ?? 0);
            if (MoneyMath::isPositive($pledged)) {
                $outstanding = MoneyMath::outstanding($pledged, $donation->collected_amount ?? 0);
                $applied = MoneyMath::min($amount, $outstanding);
                $advanceRemainder = MoneyMath::subtract($amount, $applied);
            }
            if (MoneyMath::isPositive($applied)) {
                $this->donationBalanceService->applyPayment($userId, $donation, $applied);
            }
        } elseif ($type === 'project') {
            $project = DonationProject::forTenant($tenantId)->find($allocatableId);
            if ($project) {
                $familyId = $payment->family_id;
                if ($familyId) {
                    $this->projectService->recordCollection($tenantId, $project, $familyId, $applied);
                } else {
                    $project->raised_amount = MoneyMath::add($project->raised_amount ?? 0, $applied);
                    $project->save();
                    $this->projectService->maybeMarkCompleted($project->fresh());
                }
            }
        }

        if (MoneyMath::isPositive($applied)) {
            PaymentAllocation::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'allocatable_type' => $type,
                'allocatable_id' => $type === 'advance' ? $payment->id : $allocatableId,
                'amount' => $applied,
                'notes' => $notes,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        if (MoneyMath::isPositive($advanceRemainder)) {
            PaymentAllocation::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'allocatable_type' => 'advance',
                'allocatable_id' => $payment->id,
                'amount' => $advanceRemainder,
                'notes' => 'Surplus over target recorded as unallocated family credit',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            return MoneyMath::add($applied, $advanceRemainder);
        }

        return $applied;
    }

    private function unwindAmount(int $tenantId, int $userId, DonationPayment $payment, string $amount): void
    {
        $remaining = $amount;
        $allocations = $payment->allocations->sortBy(function (PaymentAllocation $allocation) {
            $index = array_search($allocation->allocatable_type, self::UNWIND_ORDER, true);

            return $index === false ? 99 : $index;
        });

        foreach ($allocations as $allocation) {
            if (! MoneyMath::isPositive($remaining)) {
                break;
            }

            $take = MoneyMath::min($remaining, $allocation->amount);
            $this->unwindAllocation($tenantId, $userId, $payment, $allocation, $take);
            $remaining = MoneyMath::subtract($remaining, $take);
        }
    }

    private function unwindAllocation(
        int $tenantId,
        int $userId,
        DonationPayment $payment,
        PaymentAllocation $allocation,
        string $amount
    ): void {
        if ($allocation->allocatable_type === 'due') {
            $due = ContributionDue::forTenant($tenantId)->find($allocation->allocatable_id);
            if ($due) {
                ContributionBalance::unwindPaid($due, $amount);
                $due->status = ContributionBalance::statusFromPaid($due);
                $due->updated_by = $userId;
                $due->save();
            }

            return;
        }

        if ($allocation->allocatable_type === 'project_installment') {
            $installment = ProjectInstallmentDue::forTenant($tenantId)->find($allocation->allocatable_id);
            if ($installment) {
                $this->projectInstallmentService->reversePayment($tenantId, $userId, $installment, $amount);
            }

            return;
        }

        if ($allocation->allocatable_type === 'project') {
            $project = DonationProject::forTenant($tenantId)->find($allocation->allocatable_id);
            if ($project) {
                $familyId = $payment->family_id;
                if ($familyId) {
                    $this->projectService->reverseCollection($tenantId, $project, $familyId, $amount);
                } else {
                    $project->raised_amount = MoneyMath::floorAtZero(MoneyMath::subtract($project->raised_amount ?? 0, $amount));
                    $project->save();
                }
            }

            return;
        }

        if ($allocation->allocatable_type === 'donation') {
            $donation = Donation::forTenant($tenantId)->find($allocation->allocatable_id);
            if ($donation) {
                $this->donationBalanceService->reversePayment($userId, $donation, $amount);
            }
        }
    }

    private function assertAllocatableFamily(DonationPayment $payment, ?string $targetFamilyId, string $type, string $allocatableId): void
    {
        if (! $targetFamilyId) {
            return;
        }

        if ((string) $payment->family_id !== (string) $targetFamilyId) {
            $this->securityEvents->record('allocation_family_mismatch', (int) $payment->tenant_id, $type, $allocatableId, 422, [
                'payment_family_id' => $payment->family_id,
                'target_family_id' => $targetFamilyId,
            ]);
            throw new \RuntimeException('This contribution does not belong to the selected family.');
        }
    }

    private function isValidAllocatable(int $tenantId, string $type, string $id): bool
    {
        return match ($type) {
            'due' => ContributionDue::forTenant($tenantId)->where('id', $id)->exists(),
            'donation' => Donation::forTenant($tenantId)->where('id', $id)->exists(),
            'project' => DonationProject::forTenant($tenantId)->where('id', $id)->exists(),
            'project_installment' => ProjectInstallmentDue::forTenant($tenantId)->where('id', $id)->exists(),
            'plan' => ContributionPlan::forTenant($tenantId)->where('id', $id)->exists(),
            'fund' => Fund::forTenant($tenantId)->where('id', $id)->where('status', 'active')->exists(),
            default => false,
        };
    }

    private function assertFamilyInTenant(int $tenantId, ?string $familyId): void
    {
        if (! $familyId) {
            return;
        }

        $exists = Family::query()->where('id', $familyId)->where('tenant_id', $tenantId)->exists();
        if ($exists) {
            return;
        }

        $foreign = Family::query()->where('id', $familyId)->exists();
        if ($foreign) {
            $this->securityEvents->record('idor_denied', $tenantId, 'family', $familyId, 422);
        }

        throw new \RuntimeException('Family does not belong to the tenant.');
    }

    private function assertDonorInTenant(int $tenantId, ?string $donorId): void
    {
        if (! $donorId) {
            return;
        }

        $exists = Donor::forTenant($tenantId)->where('id', $donorId)->exists();
        if ($exists) {
            return;
        }

        $foreign = Donor::query()->where('id', $donorId)->exists();
        if ($foreign) {
            $this->securityEvents->record('idor_denied', $tenantId, 'donor', $donorId, 422);
        }

        throw new \RuntimeException('Donor does not belong to the tenant.');
    }

    private function normalizeReference(mixed $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $value = trim((string) $reference);

        return $value === '' ? null : $value;
    }

    private function assertUniqueReference(int $tenantId, ?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $exists = DonationPayment::forTenant($tenantId)
            ->where('gateway_reference', $reference)
            ->exists();

        if ($exists) {
            throw new \RuntimeException('This payment reference is already recorded for this church.');
        }
    }

    private function notifyRefundRequested(
        int $tenantId,
        int $userId,
        DonationApproval $approval,
        DonationPayment $payment,
        string $amount,
    ): void {
        if (! interface_exists(NotificationPublisherContract::class)) {
            return;
        }

        try {
            $actor = User::query()->find($userId);
            $payment->loadMissing('family');

            app(NotificationPublisherContract::class)->publish(
                new NotificationIntent(
                    definitionCode: 'donations.refund.requested',
                    actor: $actor,
                    subjectType: 'donation_approval',
                    subjectId: (string) $approval->id,
                    tenantId: $tenantId,
                    scope: InboxScope::Tenant,
                    occurrenceId: (string) $approval->id,
                    data: [
                        'amount' => $amount,
                        'family_name' => $payment->family?->family_name ?? 'a family',
                        'deep_link_route' => '/donations/approvals',
                    ],
                    actionStatus: 'required',
                    collapseKey: 'donation.refund.'.$approval->id,
                )
            );
        } catch (\Throwable) {
            // notification must not block refund request
        }
    }

    private function resolvePaymentCurrency(int $tenantId): string
    {
        $code = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);
        if (! is_string($code) || $code === '') {
            throw new \RuntimeException('Parish currency is not configured.');
        }

        return $code;
    }
}
