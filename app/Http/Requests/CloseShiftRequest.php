<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware (role:admin)
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shift = $this->route('shift');

            if ($shift && $shift->status !== ShiftStatus::Open) {
                $validator->errors()->add(
                    'status',
                    'Somente turnos abertos podem ser encerrados.'
                );
            }
        });
    }
}
