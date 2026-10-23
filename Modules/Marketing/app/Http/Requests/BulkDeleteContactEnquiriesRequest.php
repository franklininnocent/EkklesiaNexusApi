<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteContactEnquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', Rule::exists('contact_enquiries', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one enquiry to delete.',
            'ids.min' => 'Select at least one enquiry to delete.',
        ];
    }
}
