<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own back-office password.
 *
 * The current password is required even though the operator is already signed
 * in: it is what stops an unattended, still-logged-in browser being used to
 * take the account over.
 */
class UpdatePasswordRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `current_password` verifies against the signed-in user's hash.
            'current_password' => ['required', 'string', 'current_password'],

            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'current_password.current_password' => 'That is not your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'The new password must be different from the current one.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }
}
