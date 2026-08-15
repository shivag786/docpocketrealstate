<?php

namespace App\Http\Requests\Property;

use App\Enums\PropertyStatus;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StorePropertyRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $propertyId = $this->route('property')?->id;

        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // Unique within the project only — two projects may each have a
            // site with the same code.
            'property_code' => [
                'required', 'string', 'max:64',
                Rule::unique('properties', 'property_code')
                    ->where('project_id', $this->input('project_id'))
                    ->ignore($propertyId),
            ],

            'details' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', new Enum(PropertyStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'property_code.unique' => 'This project already has a property with that code.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'project_id' => 'project',
            'property_code' => 'property code',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status') ?: PropertyStatus::Active->value,
        ]);
    }
}
