<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class StorePlanAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'family_id' => [
                'required',
                'uuid',
                Rule::exists('families', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_exempt' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
            'revision_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
