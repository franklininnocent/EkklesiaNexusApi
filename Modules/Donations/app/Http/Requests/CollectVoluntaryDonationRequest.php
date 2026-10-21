<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Donations\Support\FinancialAmount;
use Modules\Tenants\Support\TenantContext;

class CollectVoluntaryDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('tenant_id');
        $this->offsetUnset('status');
        $this->offsetUnset('receipt_number');
        $this->offsetUnset('payment_number');
        $this->offsetUnset('currency');
    }

    public function rules(): array
    {
        $method = $this->input('method');
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'donation_id' => ['nullable', 'uuid'],
            'donor_id' => ['nullable', 'uuid'],
            'donor_name' => ['nullable', 'string', 'max:180'],
            'donor_email' => ['nullable', 'email', 'max:180'],
            'donor_phone' => ['nullable', 'string', 'max:60'],
            'donor_type' => ['nullable', 'in:family,individual,external,organization'],
            'family_id' => ['nullable', 'uuid'],
            'family_member_id' => ['nullable', 'uuid'],
            'donation_category_id' => [
                'nullable',
                'uuid',
                Rule::exists('donation_categories', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'title' => ['nullable', 'string', 'max:180'],
            'pledged_amount' => FinancialAmount::optional(),
            'received_at' => ['nullable', 'date'],
            'financial_year' => ['nullable', 'string', 'max:20'],
            'is_anonymous' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'amount' => FinancialAmount::required(),
            'currency' => ['nullable', 'string', 'max:10'],
            'method' => ['required', 'in:cash,bank_transfer,cheque,online_placeholder,adjustment'],
            'payment_date' => ['required', 'date'],
            'payer_name' => ['nullable', 'string', 'max:180'],
            'payer_email' => ['nullable', 'email', 'max:180'],
            'payer_phone' => ['nullable', 'string', 'max:60'],
            'payment_notes' => ['nullable', 'string'],
            'gateway_reference' => [
                Rule::requiredIf(in_array($method, ['cheque', 'bank_transfer'], true)),
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }
}
