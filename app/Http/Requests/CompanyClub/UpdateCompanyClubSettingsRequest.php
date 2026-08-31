<?php

namespace App\Http\Requests\CompanyClub;

use App\Http\Requests\BaseFormRequest;

class UpdateCompanyClubSettingsRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:100'],

            // Money, so bounded on both sides: a zero rate would silently stop
            // paying anybody, and an unbounded one would overflow the column.
            'reward_rate' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],

            // At least one level, or nobody could ever qualify.
            'max_upline_levels' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'display name',
            'reward_rate' => 'reward rate',
            'max_upline_levels' => 'maximum upline levels',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reward_rate.min' => 'The reward rate must be greater than zero, otherwise no member could ever be paid.',
            'max_upline_levels.min' => 'At least one upline level must be eligible.',
        ];
    }
}
