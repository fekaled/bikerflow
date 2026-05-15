<?php

namespace App\Http\Controllers\RestaurantManager;

use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TickTripRequest;
use App\Models\Shift;
use App\Models\ShiftBiker;

/**
 * Phase 2D: ShiftTrackingController — Live Tick Tracking for Restaurant Managers.
 *
 * Provides a dashboard view showing open shifts with assigned bikers,
 * and a tick endpoint to increment a biker's trip count by 1.
 */
class ShiftTrackingController extends Controller
{
    /**
     * Display the live shift tracking dashboard.
     *
     * Shows open shifts filtered by the authenticated user's restaurant.
     * Admin sees all open shifts across all restaurants.
     */
    public function dashboard()
    {
        $user = request()->user();

        $query = Shift::where('status', ShiftStatus::Open)
            ->with('shiftBikers.biker')
            ->orderBy('started_at', 'desc');

        if ($user->isRestaurantManager()) {
            $query->where('restaurant_id', $user->restaurant_id);
        }

        $shifts = $query->get();

        return view('tracking.dashboard', compact('shifts'));
    }

    /**
     * Increment a biker's trip count by 1 (Live Tick).
     *
     * Validation is handled by TickTripRequest (BR-01, shift open, biker assigned, auth).
     */
    public function tick(TickTripRequest $request, Shift $shift)
    {
        $shiftBiker = ShiftBiker::where('shift_id', $shift->id)
            ->where('biker_id', $request->input('biker_id'))
            ->first();

        // Defense-in-depth: should never hit this due to validation, but guard anyway
        if ($shiftBiker === null) {
            abort(404);
        }

        // Increment trips_count by 1
        $shiftBiker->trips_count += 1;
        $shiftBiker->save();

        return redirect()
            ->route('tracking.dashboard')
            ->with('success', 'Viagem registrada!');
    }
}
