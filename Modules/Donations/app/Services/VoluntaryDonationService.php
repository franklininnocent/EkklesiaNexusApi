<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\Donor;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;

class VoluntaryDonationService
{
    public function __construct(
        private readonly DonationLedgerService $ledgerService,
        private readonly DonationEntryBalanceService $balanceService,
        private readonly DonationAuditService $auditService
    ) {}

    /**
     * @return array{donation: Donation, payment: DonationPayment}
     */
    public function collect(int $tenantId, int $userId, array $payload): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): array {
            $this->validateFamilyReferences($tenantId, $payload);

            $isAnonymous = (bool) ($payload['is_anonymous'] ?? false);
            $amount = MoneyMath::normalize($payload['amount'] ?? 0);
            $donor = $this->resolveDonor($tenantId, $userId, $payload, $isAnonymous);

            if (! empty($payload['donation_id'])) {
                $donation = Donation::forTenant($tenantId)->findOrFail($payload['donation_id']);
            } else {
                $pledgedAmount = isset($payload['pledged_amount'])
                    ? MoneyMath::normalize($payload['pledged_amount'])
                    : $amount;

                $donation = Donation::create([
                    'tenant_id' => $tenantId,
                    'donor_id' => $donor?->id,
                    'family_id' => $payload['family_id'] ?? null,
                    'family_member_id' => $payload['family_member_id'] ?? null,
                    'donation_category_id' => $payload['donation_category_id'] ?? null,
                    'title' => $payload['title'] ?? null,
                    'pledged_amount' => $pledgedAmount,
                    'collected_amount' => 0,
                    'received_at' => $payload['received_at'] ?? $payload['payment_date'] ?? now()->toDateString(),
                    'financial_year' => $payload['financial_year'] ?? $this->resolveFinancialYear($tenantId),
                    'status' => 'pledged',
                    'notes' => $payload['notes'] ?? null,
                    'is_anonymous' => $isAnonymous,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                $this->auditService->log(
                    $tenantId,
                    'donation.created',
                    'donation',
                    $donation->id,
                    null,
                    $donation->toArray()
                );
            }

            $payerName = $isAnonymous
                ? 'Anonymous Donor'
                : ($payload['payer_name'] ?? $donor?->name ?? 'Donor');

            $payment = $this->ledgerService->createPayment($tenantId, $userId, [
                'family_id' => $payload['family_id'] ?? $donation->family_id,
                'donor_id' => $donor?->id,
                'is_anonymous' => $isAnonymous,
                'payer_name' => $payerName,
                'payer_email' => $isAnonymous ? null : ($payload['payer_email'] ?? $donor?->email),
                'payer_phone' => $isAnonymous ? null : ($payload['payer_phone'] ?? $donor?->phone),
                'payment_date' => $payload['payment_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'method' => $payload['method'] ?? 'cash',
                'gateway_reference' => $payload['gateway_reference'] ?? null,
                'status' => $payload['status'] ?? 'succeeded',
                'source_type' => $payload['source_type'] ?? 'voluntary',
                'notes' => $payload['payment_notes'] ?? $payload['notes'] ?? null,
                'allocations' => [
                    [
                        'allocatable_type' => 'donation',
                        'allocatable_id' => $donation->id,
                        'amount' => $amount,
                    ],
                ],
            ]);

            return [
                'donation' => $donation->fresh(['donor', 'category']),
                'payment' => $payment,
            ];
        });
    }

    public function applyPayment(int $tenantId, int $userId, Donation $donation, float $amount): void
    {
        $this->balanceService->applyPayment($userId, $donation, $amount);
    }

    public function reversePayment(int $tenantId, int $userId, Donation $donation, float $amount): void
    {
        $this->balanceService->reversePayment($userId, $donation, $amount);
    }

    private function resolveDonor(int $tenantId, int $userId, array $payload, bool $isAnonymous): ?Donor
    {
        if ($isAnonymous) {
            return null;
        }

        if (! empty($payload['donor_id'])) {
            $donor = Donor::forTenant($tenantId)->find($payload['donor_id']);
            if (! $donor) {
                throw new \RuntimeException('Donor does not belong to the tenant.');
            }

            return $donor;
        }

        if (empty($payload['donor_name'])) {
            return null;
        }

        $donor = Donor::create([
            'tenant_id' => $tenantId,
            'family_id' => $payload['family_id'] ?? null,
            'family_member_id' => $payload['family_member_id'] ?? null,
            'name' => $payload['donor_name'],
            'email' => $payload['donor_email'] ?? null,
            'phone' => $payload['donor_phone'] ?? null,
            'donor_type' => $payload['donor_type'] ?? (! empty($payload['family_id']) ? 'family' : 'external'),
            'is_anonymous' => false,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->auditService->log($tenantId, 'donor.created', 'donor', $donor->id, null, $donor->toArray());

        return $donor;
    }

    private function validateFamilyReferences(int $tenantId, array $payload): void
    {
        if (! empty($payload['family_id'])) {
            $familyExists = Family::query()
                ->where('id', $payload['family_id'])
                ->where('tenant_id', $tenantId)
                ->exists();
            if (! $familyExists) {
                throw new \RuntimeException('Family does not belong to the tenant.');
            }
        }

        if (! empty($payload['family_member_id'])) {
            $memberExists = FamilyMember::query()
                ->where('id', $payload['family_member_id'])
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->exists();
            if (! $memberExists) {
                throw new \RuntimeException('Family member does not belong to the tenant.');
            }
        }
    }

    private function resolveFinancialYear(int $tenantId): string
    {
        return app(ChurchFinancialPeriodResolver::class)
            ->currentFiscalYear($tenantId)
            ->key;
    }
}
