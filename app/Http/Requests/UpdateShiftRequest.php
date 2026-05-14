<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftRequest extends FormRequest
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
            'restaurant_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'workflow_type' => [
                'sometimes',
                'in:live_tick,manual_entry',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $shift = $this->route('shift');

                    if ($shift && $shift->status !== ShiftStatus::Draft) {
                        // Allow if the value hasn't actually changed
                        $currentValue = $shift->workflow_type instanceof BackedEnum
                            ? $shift->workflow_type->value
                            : (string) $shift->workflow_type;

                        if ($value !== $currentValue) {
                            $fail('Não é possível alterar o método de rastreamento após o turno ter saído do status rascunho.');
                        }
                    }
                },
            ],
            'restaurant_id' => ['sometimes', 'exists:restaurants,id'],
        ];
    }
}
