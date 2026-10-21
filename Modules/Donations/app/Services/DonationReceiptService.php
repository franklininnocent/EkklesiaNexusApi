<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\MoneyMath;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class DonationReceiptService
{
    public function __construct(
        private readonly DonationNumberSequenceService $sequences,
        private readonly DonationAuditService $auditService
    ) {}

    public function createReceipt(int $tenantId, int $userId, DonationPayment $payment, ?string $replacesReceiptId = null): DonationReceipt
    {
        $receipt = DonationReceipt::create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'receipt_number' => $this->sequences->nextReceiptNumber($tenantId),
            'issued_on' => now()->toDateString(),
            'issued_by' => $userId,
            'is_void' => false,
            'replaces_receipt_id' => $replacesReceiptId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $snapshot = $this->buildReceiptPayload($tenantId, $payment->fresh(['allocations', 'family', 'donor']), $receipt);
        $receipt->snapshot = $this->jsonSafe($snapshot);
        $receipt->save();

        $this->auditService->log(
            $tenantId,
            'receipt.issued',
            'receipt',
            $receipt->id,
            null,
            ['receipt_number' => $receipt->receipt_number, 'payment_id' => $payment->id]
        );

        return $receipt->fresh();
    }

    public function voidReceipt(int $tenantId, int $userId, DonationReceipt $receipt, string $reason, bool $allowReplace = false): DonationReceipt
    {
        if ($receipt->is_void) {
            throw new \RuntimeException('Receipt is already voided.');
        }

        $old = $receipt->toArray();
        $receipt->is_void = true;
        $receipt->void_reason = $reason;
        $receipt->voided_at = now();
        $receipt->voided_by = $userId;
        $receipt->updated_by = $userId;
        $receipt->save();

        $this->auditService->log(
            $tenantId,
            'receipt.voided',
            'receipt',
            $receipt->id,
            $old,
            $receipt->toArray(),
            ['reason' => $reason, 'allow_replace' => $allowReplace]
        );

        return $receipt->fresh();
    }

    public function reissueReceipt(int $tenantId, int $userId, DonationPayment $payment, string $reason): DonationReceipt
    {
        if (in_array($payment->status, ['reversed', 'refunded'], true)) {
            throw new \RuntimeException('A replacement receipt cannot be issued for a reversed or fully refunded payment.');
        }

        $current = $this->currentReceipt($payment);
        if (! $current) {
            return $this->createReceipt($tenantId, $userId, $payment);
        }

        $voided = $this->voidReceipt($tenantId, $userId, $current, $reason, true);
        $replacement = $this->createReceipt($tenantId, $userId, $payment, $voided->id);
        $voided->replaced_by_receipt_id = $replacement->id;
        $voided->save();

        return $replacement;
    }

    public function currentReceipt(DonationPayment $payment): ?DonationReceipt
    {
        return DonationReceipt::query()
            ->where('payment_id', $payment->id)
            ->where('is_void', false)
            ->latest('created_at')
            ->first();
    }

    public function latestReceipt(DonationPayment $payment): ?DonationReceipt
    {
        return $this->currentReceipt($payment)
            ?: DonationReceipt::query()->where('payment_id', $payment->id)->latest('created_at')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReceiptPayload(int $tenantId, DonationPayment $payment, DonationReceipt $receipt): array
    {
        if (is_array($receipt->snapshot) && $receipt->snapshot !== []) {
            $payload = $receipt->snapshot;
            $payload['receipt'] = $this->receiptArray($receipt);
            $payload['void'] = [
                'is_void' => (bool) $receipt->is_void,
                'void_reason' => $receipt->void_reason,
            ];

            return $payload;
        }

        $payment->loadMissing(['allocations', 'family', 'donor']);
        $settings = DonationSetting::forTenant($tenantId)->first();
        $taxDeductibleTotal = '0.00';
        $lineItems = [];

        foreach ($payment->allocations as $allocation) {
            $amount = MoneyMath::normalize($allocation->amount);
            $line = $this->receiptLine($tenantId, $allocation->allocatable_type, (string) $allocation->allocatable_id, $amount);
            if ($line['is_tax_deductible']) {
                $taxDeductibleTotal = MoneyMath::add($taxDeductibleTotal, $amount);
            }
            $lineItems[] = $line;
        }

        $currency = $payment->currency
            ?: app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);
        $isAnonymous = (bool) $payment->is_anonymous;

        return [
            'receipt' => $this->receiptArray($receipt),
            'payment' => [
                'id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'method' => $payment->method,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'status' => $payment->status,
                'amount' => MoneyMath::toApiNumber($payment->amount),
                'currency' => $currency,
            ],
            'settings' => $settings,
            'payer' => [
                'name' => $isAnonymous ? 'Anonymous Donor' : $payment->payer_name,
                'email' => $isAnonymous ? null : $payment->payer_email,
                'phone' => $isAnonymous ? null : $payment->payer_phone,
                'is_anonymous' => $isAnonymous,
            ],
            'line_items' => $lineItems,
            'tax_acknowledgement' => [
                'registration_number' => $settings?->tax_registration_number,
                'note' => $settings?->tax_acknowledgement_note,
                'tax_deductible_amount' => MoneyMath::toApiNumber($taxDeductibleTotal),
                'has_tax_deductible_portion' => MoneyMath::isPositive($taxDeductibleTotal),
            ],
            'totals' => [
                'amount' => MoneyMath::toApiNumber($payment->amount),
                'currency' => $currency,
            ],
            'void' => [
                'is_void' => (bool) $receipt->is_void,
                'void_reason' => $receipt->void_reason,
            ],
        ];
    }

    /**
     * @return array{description: string, category: ?string, amount: float|int, is_tax_deductible: bool}
     */
    private function receiptLine(int $tenantId, string $type, string $allocatableId, string $amount): array
    {
        $description = 'Collection';
        $category = null;
        $taxDeductible = false;

        if ($type === 'donation') {
            $donation = Donation::forTenant($tenantId)->with('category')->find($allocatableId);
            $description = $donation?->title ?: ($donation?->category?->name ?? 'Voluntary gift');
            $category = $donation?->category?->name;
            $taxDeductible = (bool) ($donation?->category?->is_tax_deductible ?? false);
        } elseif ($type === 'due') {
            $due = ContributionDue::forTenant($tenantId)->with('plan.fund')->find($allocatableId);
            $description = trim(($due?->plan?->name ?? 'Family due').($due?->period_label ? ' · '.$due->period_label : ''));
            $category = $due?->plan?->fund?->name;
        } elseif ($type === 'project_installment') {
            $installment = ProjectInstallmentDue::forTenant($tenantId)->with('project')->find($allocatableId);
            $description = $installment?->installment_label ?: 'Project instalment';
            $category = $installment?->project?->name;
        } elseif ($type === 'project') {
            $project = DonationProject::forTenant($tenantId)->find($allocatableId);
            $description = $project?->name ?: 'Project';
            $category = $project?->fund?->name ?? null;
        } elseif ($type === 'fund') {
            $fund = Fund::forTenant($tenantId)->find($allocatableId);
            $description = $fund?->name ?: 'Collection purpose';
            $category = $fund?->name;
        } elseif ($type === 'advance') {
            $description = 'Family credit';
        }

        return [
            'description' => $description,
            'category' => $category,
            'amount' => MoneyMath::toApiNumber($amount),
            'is_tax_deductible' => $taxDeductible,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptArray(DonationReceipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'issued_on' => optional($receipt->issued_on)->toDateString(),
            'is_void' => (bool) $receipt->is_void,
            'void_reason' => $receipt->void_reason,
            'replaces_receipt_id' => $receipt->replaces_receipt_id,
            'replaced_by_receipt_id' => $receipt->replaced_by_receipt_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function jsonSafe(array $payload): array
    {
        return json_decode(json_encode($payload), true) ?? [];
    }
}
