<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Company identity, as edited on the Settings screen.
 *
 * Nothing validated here reaches a reward calculation. The strictness is about
 * what ends up on a printed document going out to a member: an oversized logo
 * that dompdf chokes on, or a designation list with a blank entry that renders
 * an empty option on the member form.
 */
class UpdateCompanySettingsRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $mimes = (array) config('company.uploads.mimes', ['png', 'jpg', 'jpeg']);
        $maxKb = (int) config('company.uploads.max_kb', 1024);

        return [
            'company_name' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:200'],

            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s(),]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],

            'authority_name' => ['nullable', 'string', 'max:150'],
            'authority_designation' => ['nullable', 'string', 'max:100'],

            // Uploads are optional on every save: an admin correcting a phone
            // number must not be forced to re-attach the logo.
            'logo' => ['nullable', 'image', Rule::file()->types($mimes)->max($maxKb)],
            'signature' => ['nullable', 'image', Rule::file()->types($mimes)->max($maxKb)],

            // Explicit removal, because "leave the file field empty" already
            // means "keep what is there".
            'remove_logo' => ['nullable', 'boolean'],
            'remove_signature' => ['nullable', 'boolean'],

            // Edited as a textarea, one rank per line. Normalised into the
            // stored array by the service; validated here as raw text so the
            // operator sees a message about what they typed.
            'designations' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number may only contain digits, spaces and + - ( ) , characters.',
            'logo.max' => 'The logo must be :max KB or smaller.',
            'signature.max' => 'The signature must be :max KB or smaller.',
            'designations.required' => 'Enter at least one designation — every member must hold one.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'company name',
            'authority_name' => 'authorised signatory',
            'authority_designation' => "signatory's designation",
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tagline' => trim((string) $this->input('tagline')) ?: null,
            'address' => trim((string) $this->input('address')) ?: null,
            'phone' => trim((string) $this->input('phone')) ?: null,
            'email' => trim((string) $this->input('email')) ?: null,
            'website' => trim((string) $this->input('website')) ?: null,
            'authority_name' => trim((string) $this->input('authority_name')) ?: null,
            'authority_designation' => trim((string) $this->input('authority_designation')) ?: null,
        ]);
    }
}
