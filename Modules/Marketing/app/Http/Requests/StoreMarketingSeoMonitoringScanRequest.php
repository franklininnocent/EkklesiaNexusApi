<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingSeoMonitoringScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include_production' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $allowed = ['include_production'];
            $extra = array_diff(array_keys($this->all()), $allowed);
            if ($extra !== []) {
                $validator->errors()->add('payload', 'Unknown fields: '.implode(', ', $extra));
            }
        });
    }

    public function includeProduction(): bool
    {
        return (bool) $this->boolean('include_production');
    }
}
