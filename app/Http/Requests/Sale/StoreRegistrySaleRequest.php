<?php

namespace App\Http\Requests\Sale;

use App\Enums\MemberStatus;
use App\Enums\PropertyStatus;
use App\Http\Requests\BaseFormRequest;
use App\Models\Member;
use App\Models\Property;
use Illuminate\Validation\Rule;

class StoreRegistrySaleRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'property_id' => ['required', 'integer', Rule::exists('properties', 'id')->whereNull('deleted_at')],

            // Unique: one legal registration must not be recorded twice. This is
            // the duplicate-sale guard, and it is enforced in the database too.
            'registry_reference' => ['required', 'string', 'max:100', Rule::unique('registry_sales', 'registry_reference')],

            // Client-confirmed: the entry day is the registry date. It defaults
            // to today and may not be in the future, because a sale cannot be
            // counted in a month that has not happened.
            'registry_date' => ['required', 'date', 'before_or_equal:today'],
            'sale_date' => ['nullable', 'date', 'before_or_equal:registry_date'],

            // Sq.Ft. drives every reward. Bounded above zero, capped to the
            // DECIMAL(12,2) column, and limited to 2 decimal places.
            'sqft' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $propertyId = $this->input('property_id');
            $projectId = $this->input('project_id');

            if (! $propertyId || ! $projectId) {
                return;
            }

            $property = Property::find($propertyId);

            if ($property && (int) $property->project_id !== (int) $projectId) {
                $validator->errors()->add(
                    'property_id',
                    'The selected property does not belong to the selected project.'
                );
            }

            if ($property && $property->status !== PropertyStatus::Active) {
                $validator->errors()->add(
                    'property_id',
                    'This property is inactive and cannot be used for a new sale.'
                );
            }

            $member = Member::find($this->input('member_id'));

            if ($member && $member->status !== MemberStatus::Active) {
                $validator->errors()->add(
                    'member_id',
                    'This member is inactive. Activate the member before recording a sale for them.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registry_reference.unique' => 'A sale with this registry number has already been recorded.',
            'registry_date.before_or_equal' => 'The registry date cannot be in the future.',
            'sale_date.before_or_equal' => 'The sale date cannot be after the registry date.',
            'sqft.gt' => 'Sq.Ft. must be greater than zero.',
            'sqft.decimal' => 'Sq.Ft. may have at most 2 decimal places.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'member_id' => 'member',
            'project_id' => 'project',
            'property_id' => 'property',
            'registry_reference' => 'registry number',
            'registry_date' => 'registry date',
            'sqft' => 'Sq.Ft.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'registry_date' => $this->input('registry_date') ?: now()->toDateString(),
            'sale_date' => $this->input('sale_date') ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = $this->validated();

        // sale_date is retained from the documented schema for reporting; when
        // not supplied it mirrors the registry date.
        $data['sale_date'] ??= $data['registry_date'];

        return $data;
    }
}
