<?php

namespace App\Http\Requests\Sale;

use App\Enums\MemberStatus;
use App\Enums\PropertyStatus;
use App\Http\Requests\BaseFormRequest;
use App\Models\Member;
use App\Models\Property;
use Illuminate\Validation\Rule;

/**
 * CLIENT-CONFIRMED (2026-08-15, correction after Phase 5):
 *
 * A sale requires only a member and a Sq.Ft. figure. Project, property, registry
 * number and registry date are optional supporting detail.
 *
 * Everything supplied is still validated strictly — optional never means
 * unchecked. A property given without its project, a property from the wrong
 * project, a duplicate registry number or a future date are all still rejected.
 */
class StoreRegistrySaleRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // --- Required ---------------------------------------------------
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],

            // Sq.Ft. drives the whole reward. Numeric only, above zero, at most
            // two decimals, and inside the DECIMAL(12,2) column.
            'sqft' => ['required', 'numeric', 'gt:0', 'lte:9999999999.99', 'decimal:0,2'],

            // --- Optional detail ---------------------------------------------
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')->whereNull('deleted_at')],

            // Unique when supplied. MySQL permits many NULLs in a unique index,
            // so omitting it is allowed while duplicates of a real number are not.
            'registry_reference' => [
                'nullable', 'string', 'max:100',
                Rule::unique('registry_sales', 'registry_reference'),
            ],

            // Optional in the form. When omitted the application uses the entry
            // date, which is the confirmed rule for deciding the reward month.
            'registry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'sale_date' => ['nullable', 'date', 'before_or_equal:registry_date'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $projectId = $this->input('project_id');
            $propertyId = $this->input('property_id');

            // A property cannot be recorded without saying which project it is in.
            if ($propertyId && ! $projectId) {
                $validator->errors()->add('project_id', 'Select the project this property belongs to.');
            }

            if ($propertyId && $projectId) {
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
            'member_id.required' => 'Search for and select the member who made this sale.',
            'sqft.required' => 'Enter the Sq.Ft. sold.',
            'sqft.numeric' => 'Sq.Ft. must be a number — digits and a decimal point only.',
            'sqft.gt' => 'Sq.Ft. must be greater than zero.',
            'sqft.decimal' => 'Sq.Ft. may have at most 2 decimal places.',
            'sqft.lte' => 'That Sq.Ft. figure is too large. Please check it.',
            'registry_reference.unique' => 'A sale with this registry number has already been recorded.',
            'registry_date.before_or_equal' => 'The registry date cannot be in the future.',
            'sale_date.before_or_equal' => 'The sale date cannot be after the registry date.',
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
        // Blank optional fields arrive as "" from the form; normalise to null so
        // "nullable" behaves and no empty string reaches a unique index.
        $this->merge([
            'project_id' => $this->input('project_id') ?: null,
            'property_id' => $this->input('property_id') ?: null,
            'registry_reference' => trim((string) $this->input('registry_reference')) ?: null,
            'registry_date' => $this->input('registry_date') ?: null,
            'sale_date' => $this->input('sale_date') ?: null,
            'sqft' => is_string($this->input('sqft'))
                ? trim(str_replace(',', '', $this->input('sqft')))
                : $this->input('sqft'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function saleData(): array
    {
        $data = $this->validated();

        // The reward month comes from the registry date; when the admin does not
        // supply one, the entry day is used.
        $data['registry_date'] ??= now()->toDateString();
        $data['sale_date'] ??= $data['registry_date'];

        return $data;
    }
}
