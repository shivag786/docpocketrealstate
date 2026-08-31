<?php

namespace App\Http\Requests\Member;

use App\Enums\BloodGroup;
use App\Enums\MemberStatus;
use App\Http\Requests\BaseFormRequest;
use App\Models\CompanySetting;
use App\Models\Member;
use App\Rules\ValidSponsor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateMemberRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $member = $this->member();

        return [
            'name' => ['required', 'string', 'max:255'],
            'mobile' => [
                'required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/',
                Rule::unique('members', 'mobile')->ignore($member->id),
            ],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('members', 'email')->ignore($member->id),
            ],
            'blood_group' => ['nullable', new Enum(BloodGroup::class)],

            // The member's CURRENT designation is always permitted, even if an
            // admin has since removed that rank from the list. Otherwise every
            // unrelated edit — a corrected mobile number — would be blocked by
            // a field the operator never touched, on a member who was issued a
            // printed card under the old rank.
            'designation' => [
                'required', 'string', 'max:100',
                Rule::in([
                    ...CompanySetting::current()->designationOptions(),
                    ...array_filter([$member->designation]),
                ]),
            ],
            'address' => ['nullable', 'string', 'max:1000'],

            // Passing the member lets the rule reject self-sponsorship and any
            // sponsor drawn from this member's own downline.
            'sponsor_id' => ['nullable', 'integer', new ValidSponsor($member)],

            'joining_date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', new Enum(MemberStatus::class)],
        ];
    }

    /**
     * Sponsor changes are only permitted while the member has no sales.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $member = $this->member();
            $submitted = $this->input('sponsor_id');

            $changed = (int) $submitted !== (int) $member->sponsor_id;

            if ($changed && ! $member->canChangeSponsor()) {
                $validator->errors()->add(
                    'sponsor_id',
                    'The sponsor can no longer be changed because sales have been recorded against this member.'
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
            'blood_group' => $this->input('blood_group') ?: null,
            // An update that does not mention the designation keeps the one
            // the member already holds. Every member holds one from the day
            // they join, so there is no such thing as clearing it — and a
            // partial update correcting a mobile number must not be rejected
            // over a field it never touched.
            'designation' => $this->input('designation') ?: $this->member()->designation,
        ]);
    }

    private function member(): Member
    {
        return $this->route('member');
    }
}
