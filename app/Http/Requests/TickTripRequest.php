<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Models\ShiftBiker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 2D: TickTripRequest — validates tick preconditions.
 *
 * Four validation rules:
 * 1. Shift must be open
 * 2. Shift workflow must be live_tick (BR-01)
 * 3. Biker must be assigned to this shift
 * 4. User must be the Restaurant Manager for the shift's restaurant (or Admin)
 */
class TickTripRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $shift = $this->route('shift');

        // Admin can tick any shift
        if ($user->isAdmin()) {
            return true;
        }

        // Restaurant Manager can only tick their own restaurant's shift
        if ($user->isRestaurantManager()) {
            return $shift->restaurant_id === $user->restaurant_id;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'biker_id' => ['required', 'integer'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $shift = $this->route('shift');

            // Rule 1: Shift must be open
            if ($shift->status !== ShiftStatus::Open) {
                $validator->errors()->add('shift', 'Somente turnos abertos podem receber marcações.');

                return;
            }

            // Rule 2 (BR-01): Shift workflow must be live_tick
            if ($shift->workflow_type !== WorkflowType::LiveTick) {
                $validator->errors()->add('workflow_type', 'Este turno não usa contagem em tempo real.');

                return;
            }

            // Rule 3: Biker must be assigned to this shift
            $bikerId = $this->input('biker_id');
            if ($bikerId !== null) {
                $isAssigned = ShiftBiker::where('shift_id', $shift->id)
                    ->where('biker_id', $bikerId)
                    ->exists();

                if (! $isAssigned) {
                    $validator->errors()->add('biker_id', 'Este entregador não está atribuído a este turno.');
                }
            }
        });
    }
}
