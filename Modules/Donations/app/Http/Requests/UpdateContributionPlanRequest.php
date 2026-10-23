<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class UpdateContributionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('start_date')) {
            $merge['start_date'] = $this->filled('start_date') ? $this->input('start_date') : null;
        }
        if ($this->has('end_date')) {
            $merge['end_date'] = $this->filled('end_date') ? $this->input('end_date') : null;
        }
        if ($this->has('code')) {
            $merge['code'] = $this->filled('code') ? trim((string) $this->input('code')) : null;
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $planId = $this->route('id');

        return [
            'fund_id' => ['sometimes', 'uuid', Rule::exists('donation_funds', 'id')->where('tenant_id', $tenantId)],
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => [
                'sometimes',
                'string',
                'max:60',
                Rule::unique('contribution_plans', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($planId),
            ],
            'plan_type' => ['sometimes', 'in:uniform,individual'],
            'frequency' => ['sometimes', 'in:one_time,weekly,monthly,quarterly,half_yearly,yearly,custom'],
            'custom_interval_days' => ['nullable', 'integer', 'min:1', 'max:366', 'required_if:frequency,custom'],
            'default_amount' => ['sometimes', 'numeric', 'min:0.01'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'auto_generate' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'effective_from' => ['nullable', 'date'],
            'revision_reason' => ['nullable', 'string', 'max:500'],
            'assignments' => ['nullable', 'array'],
            'assignments.*.family_id' => [
                'required_with:assignments',
                'uuid',
                Rule::exists('families', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'assignments.*.amount' => ['required_with:assignments', 'numeric', 'min:0.01'],
            'assignments.*.effective_from' => ['nullable', 'date'],
            'assignments.*.effective_to' => ['nullable', 'date'],
            'assignments.*.is_exempt' => ['nullable', 'boolean'],
            'assignments.*.status' => ['nullable', 'in:active,inactive'],
            'assignments.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('plan_type') !== 'individual') {
                return;
            }
            if (! $this->has('assignments')) {
                return;
            }
            $assignments = $this->input('assignments');
            if (! is_array($assignments) || $assignments === []) {
                $validator->errors()->add(
                    'assignments',
                    'Add at least one family amount for per-family plans.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'fund_id.exists' => 'The selected collection purpose is no longer available.',
            'code.unique' => 'A plan with this reference code already exists.',
            'default_amount.min' => 'Amount must be greater than zero.',
            'end_date.after_or_equal' => 'End date cannot be before the start date.',
        ];
    }
}
