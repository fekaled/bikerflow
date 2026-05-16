<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmCloseShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $shift = $this->route('shift');

        return $this->user()->can('close', $shift);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
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
