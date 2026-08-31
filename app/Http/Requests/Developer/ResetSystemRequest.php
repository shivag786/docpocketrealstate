<?php

namespace App\Http\Requests\Developer;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * The typed confirmation in front of a system reset.
 *
 * A dialog alone is not enough for an irreversible, whole-database action: the
 * operator has to type the word, which cannot happen by reflex or by a stray
 * click on a page they opened for something else.
 */
class ResetSystemRequest extends BaseFormRequest
{
    public const PHRASE = 'RESET';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([self::PHRASE])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'Type '.self::PHRASE.' to confirm. Nothing was deleted.',
            'confirmation.in' => 'That is not the confirmation word. Type '
                .self::PHRASE.' exactly, in capitals. Nothing was deleted.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Trimmed, but NOT upper-cased: typing the word in capitals is part of
        // the deliberateness this guard exists to require.
        $this->merge(['confirmation' => trim((string) $this->input('confirmation'))]);
    }
}
