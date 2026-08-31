<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\BaseFormRequest;

/**
 * Which optional rows the welcome letter prints.
 *
 * Unchecked checkboxes are simply absent from a form post, so every known field
 * is normalised to an explicit true/false before validation. Without that,
 * switching a row OFF would submit nothing and the old value would survive —
 * the classic checkbox bug, and here it would mean an admin cannot ever hide a
 * row they once showed.
 */
class UpdateLetterFieldsRequest extends BaseFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ['fields' => ['array']];

        foreach (self::known() as $field) {
            $rules["fields.{$field}"] = ['boolean'];
        }

        return $rules;
    }

    /**
     * The fields the letter template actually understands.
     *
     * Read from config rather than hard-coded, so this and the template cannot
     * drift apart.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return array_keys((array) config('company.letter.fields', []));
    }

    /**
     * @return array<string, bool>
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $submitted */
        $submitted = $this->validated()['fields'] ?? [];

        $fields = [];

        foreach (self::known() as $field) {
            $fields[$field] = (bool) ($submitted[$field] ?? false);
        }

        return $fields;
    }

    protected function prepareForValidation(): void
    {
        $submitted = (array) $this->input('fields', []);
        $normalised = [];

        foreach (self::known() as $field) {
            // filter_var so "0", "false" and "off" are false rather than truthy
            // non-empty strings.
            $normalised[$field] = filter_var(
                $submitted[$field] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        $this->merge(['fields' => $normalised]);
    }
}
