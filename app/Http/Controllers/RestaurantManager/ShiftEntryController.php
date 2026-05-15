<?php

namespace App\Http\Controllers\RestaurantManager;

use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitTripsRequest;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Phase 2E: ShiftEntryController — End-of-Shift Entry for Restaurant Managers.
 *
 * Provides a form for entering final trip totals for manual_entry shifts,
 * and a store endpoint to persist those totals.
 */
class ShiftEntryController extends Controller
{
    use AuthorizesRequests;
    /**
     * Display the manual trip entry form for a shift.
     *
     * Shows the shift with assigned bikers and trip count input fields.
     */
    public function show(Shift $shift)
    {
        $this->authorize('submitTrips', $shift);

        $shift->load('shiftBikers.biker', 'restaurant');

        return view('entry.show', compact('shift'));
    }

    /**
     * Submit final trip totals for all assigned bikers.
     *
     * Validation is handled by SubmitTripsRequest (BR-01, shift open, bikers assigned, auth).
     * Updates each ShiftBiker's trips_count and optionally closes the shift.
     */
    public function store(SubmitTripsRequest $request, Shift $shift)
    {
        $validatedData = $request->validated();
        $bikers = $validatedData['bikers'];

        // Update each ShiftBiker's trips_count
        foreach ($bikers as $entry) {
            $shiftBiker = ShiftBiker::where('shift_id', $shift->id)
                ->where('biker_id', $entry['biker_id'])
                ->first();

            // Defense-in-depth: should never be null due to validation
            if ($shiftBiker !== null) {
                $shiftBiker->trips_count = $entry['trips_count'];
                $shiftBiker->save();
            }
        }

        // Optionally close the shift if requested
        if (! empty($validatedData['close_shift'])) {
            $shift->status = ShiftStatus::Closed;
            $shift->closed_at = now();
            $shift->save();
        }

        return redirect()
            ->route('tracking.dashboard')
            ->with('success', 'Viagens registradas com sucesso!');
    }
}
