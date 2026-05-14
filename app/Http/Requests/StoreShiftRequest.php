<?php

namespace App\Http\Requests;

use App\Models\Restaurant;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
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
            'restaurant_id' => [
                'required',
                'exists:restaurants,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $restaurant = Restaurant::find($value);
                    if ($restaurant && ! $restaurant->active) {
                        $fail('O restaurante selecionado está inativo.');
                    }
                },
            ],
            'workflow_type' => ['required', 'in:live_tick,manual_entry'],
            'restaurant_rate' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
