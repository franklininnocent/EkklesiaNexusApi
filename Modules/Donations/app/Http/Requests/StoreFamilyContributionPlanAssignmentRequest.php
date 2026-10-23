<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class StoreFamilyContributionPlanAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'plan_id' => [
                'required',
                'uuid',
                Rule::exists('contribution_plans', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Amount must be greater than zero.',
        ];
    }
}
