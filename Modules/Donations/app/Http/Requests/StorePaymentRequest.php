<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Donations\Support\FinancialAmount;

class StorePaymentRequest extends FormRequest
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
        $this->offsetUnset('is_void');
    }

    public function rules(): array
    {
        $method = $this->input('method');

        return [
            'family_id' => ['nullable', 'uuid'],
            'donor_id' => ['nullable', 'uuid'],
            'is_anonymous' => ['nullable', 'boolean'],
            'payer_name' => ['required', 'string', 'max:180'],
            'payer_email' => ['nullable', 'email', 'max:180'],
            'payer_phone' => ['nullable', 'string', 'max:60'],
            'payment_date' => ['required', 'date'],
            'amount' => FinancialAmount::required(),
            'currency' => ['nullable', 'string', 'max:10'],
            'method' => ['required', 'in:cash,bank_transfer,cheque,online_placeholder,adjustment'],
            'gateway_reference' => [
                Rule::requiredIf(in_array($method, ['cheque', 'bank_transfer'], true)),
                'nullable',
                'string',
                'max:200',
            ],
            'source_type' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
            'collection_mode' => ['nullable', 'in:project_installments,mandatory_dues'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.allocatable_type' => ['required_with:allocations', 'in:fund,plan,project,project_installment,due,donation,advance'],
            'allocations.*.allocatable_id' => ['required_with:allocations', 'uuid'],
            'allocations.*.amount' => array_merge(['required_with:allocations'], FinancialAmount::required()),
            'allocations.*.notes' => ['nullable', 'string'],
        ];
    }
}
