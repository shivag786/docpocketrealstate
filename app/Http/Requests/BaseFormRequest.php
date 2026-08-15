<?php

namespace App\Http\Requests;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base class for every FormRequest in the application.
 *
 * Guarantees that a validation failure on an AJAX request comes back in the
 * standard ApiResponse envelope rather than Laravel's default shape, so the
 * front-end can render field errors uniformly.
 */
abstract class BaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson() || $this->ajax()) {
            throw new HttpResponseException(
                ApiResponse::validationError($validator->errors()->toArray())
            );
        }

        parent::failedValidation($validator);
    }
}
