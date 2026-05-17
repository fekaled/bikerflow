<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Phase 3C: Authorizes Admin to mark a payment as failed.
 * Validates failure_reason: required, string, 3..500 chars.
 *
 * AC-3C-22 through AC-3C-24: Validation rules.
 * AC-3C-27: Admin-only authorization.
 */
class MarkFailedRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift
            && $this->user()->can('markFailed', $shift);
    }

    public function rules(): array
    {
        return [
            'failure_reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
