<?php

namespace App\Http\Requests\Member;

use App\Enums\BloodGroup;
use App\Enums\MemberStatus;
use App\Http\Requests\BaseFormRequest;
use App\Models\CompanySetting;
use App\Rules\ValidSponsor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreMemberRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Unique across soft-deleted members too: a deleted member's mobile
            // must not silently reappear on someone else.
            'mobile' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/', Rule::unique('members', 'mobile')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('members', 'email')],

            // Optional, and one of the eight ABO/Rh groups when supplied.
            'blood_group' => ['nullable', new Enum(BloodGroup::class)],

            // Required, because every member holds a rank from the day they
            // join. Constrained to the admin-editable list rather than to an
            // enum, so the client can add a rank without a deployment.
            'designation' => [
                'required', 'string', 'max:100',
                Rule::in(CompanySetting::current()->designationOptions()),
            ],
            'address' => ['nullable', 'string', 'max:1000'],

            // Nullable: root members are allowed.
            'sponsor_id' => ['nullable', 'integer', new ValidSponsor],

            'joining_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', new Enum(MemberStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.regex' => 'The mobile number may only contain digits, spaces and + - ( ) characters.',
            'joining_date.before_or_equal' => 'The joining date cannot be in the future.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sponsor_id' => 'sponsor',
            'joining_date' => 'joining date',
            'blood_group' => 'blood group',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sponsor_id' => $this->input('sponsor_id') ?: null,
            'email' => $this->input('email') ?: null,
            'status' => $this->input('status') ?: MemberStatus::Active->value,
            'blood_group' => $this->input('blood_group') ?: null,
            'designation' => $this->input('designation')
                ?: config('company.designations.default', 'Sales Advisor'),
        ]);
    }
}
