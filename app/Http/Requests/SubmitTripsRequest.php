<?php

namespace App\Http\Requests;

use App\Enums\ShiftStatus;
use App\Enums\WorkflowType;
use App\Models\ShiftBiker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 2E: SubmitTripsRequest — validates manual trip entry preconditions.
 *
 * Four validation rules:
 * 1. Shift must be open
 * 2. Shift workflow must be manual_entry (BR-01)
 * 3. Every biker in the request must be assigned to this shift
 * 4. User must be the Restaurant Manager for the shift's restaurant (or Admin)
 */
class SubmitTripsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $shift = $this->route('shift');

        // Admin can submit trips for any shift
        if ($user->isAdmin()) {
            return true;
        }

        // Restaurant Manager can only submit for their own restaurant's shift
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
            'bikers' => ['required', 'array', 'min:1'],
            'bikers.*.biker_id' => ['required', 'integer', 'exists:bikers,id'],
            'bikers.*.trips_count' => ['required', 'integer', 'min:0'],
            'close_shift' => ['sometimes', 'boolean'],
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
                $validator->errors()->add('shift', 'Somente turnos abertos podem receber entradas.');

                return;
            }

            // Rule 2 (BR-01): Shift workflow must be manual_entry
            if ($shift->workflow_type !== WorkflowType::ManualEntry) {
                $validator->errors()->add('workflow_type', 'Este turno não usa entrada manual.');

                return;
            }

            // Rule 3: Every biker in the request must be assigned to this shift
            $bikers = $this->input('bikers', []);
            foreach ($bikers as $index => $entry) {
                $bikerId = $entry['biker_id'] ?? null;
                if ($bikerId !== null) {
                    $isAssigned = ShiftBiker::where('shift_id', $shift->id)
                        ->where('biker_id', $bikerId)
                        ->exists();

                    if (! $isAssigned) {
                        $validator->errors()->add(
                            "bikers.{$index}.biker_id",
                            'Entregador não está atribuído a este turno.'
                        );
                    }
                }
            }

            // Rule 4: All assigned bikers must be present in the submission
            $assignedBikerIds = ShiftBiker::where('shift_id', $shift->id)
                ->pluck('biker_id')
                ->toArray();

            $submittedBikerIds = collect($bikers)->pluck('biker_id')->filter()->toArray();

            $missingIds = array_diff($assignedBikerIds, $submittedBikerIds);

            if (count($missingIds) > 0) {
                $validator->errors()->add(
                    'bikers',
                    'Todos os entregadores atribuídos devem ter suas viagens registradas.'
                );
            }
        });
    }
}
