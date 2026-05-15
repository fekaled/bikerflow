<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use App\Models\Biker;
use App\Models\ShiftBiker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignBikerRequest extends FormRequest
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
            'biker_id' => [
                'required',
                'integer',
                'exists:bikers,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Check biker is active
                    $biker = Biker::find($value);
                    if ($biker && ! $biker->active) {
                        $fail('Não é possível atribuir um entregador inativo.');
                    }

                    // Check biker not already assigned to this shift
                    $shiftId = $this->route('shift')?->id;
                    if ($shiftId && ShiftBiker::where('shift_id', $shiftId)->where('biker_id', $value)->exists()) {
                        $fail('Este entregador já está atribuído a este turno.');
                    }
                },
            ],
            'biker_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'base_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
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
                $validator->errors()->add('shift', 'Bikers can only be assigned to draft or open shifts.');
            }
        });
    }
}
