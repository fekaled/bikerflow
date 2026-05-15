<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateShiftBikerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware + controller policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'biker_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'base_fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'trips_count' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $shift = $this->route('shift');

            if ($shift && ! in_array($shift->status, [ShiftStatus::Draft, ShiftStatus::Open])) {
                $validator->errors()->add('shift', 'Biker details can only be updated on draft or open shifts.');
            }
        });
    }
}
