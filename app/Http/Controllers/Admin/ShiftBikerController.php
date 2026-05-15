<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignBikerRequest;
use App\Http\Requests\UpdateShiftBikerRequest;
use App\Models\Biker;
use App\Models\Shift;
use App\Models\ShiftBiker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ShiftBikerController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the list of assigned bikers for a shift.
     */
    public function index(Request $request, Shift $shift)
    {
        $this->authorize('view', $shift);

        $shiftBikers = $shift->shiftBikers()->with('biker')->get();

        return view('shifts.partials.biker-assignments', compact('shift', 'shiftBikers'));
    }

    /**
     * Assign a biker to an open/draft shift.
     *
     * BR-05: Only Admin can add/replace bikers (enforced by middleware + policy).
     * BR-01: Only draft and open shifts accept biker assignments.
     */
    public function store(AssignBikerRequest $request, Shift $shift)
    {
        $this->authorize('addBiker', $shift);

        $validated = $request->validated();

        $biker = Biker::findOrFail($validated['biker_id']);

        // Fill defaults from Biker model if not provided
        $validated['biker_rate'] = $validated['biker_rate'] ?? $biker->rate_per_trip;
        $validated['base_fee'] = $validated['base_fee'] ?? $biker->base_fee;
        $validated['trips_count'] = 0;

        $shift->shiftBikers()->create($validated);

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Entregador atribuído com sucesso.');
    }

    /**
     * Update biker details (biker_rate, base_fee, trips_count) on a shift.
     *
     * BR-01: Only draft and open shifts allow biker detail updates.
     */
    public function update(UpdateShiftBikerRequest $request, Shift $shift, ShiftBiker $biker)
    {
        // Verify the shift_biker belongs to this shift
        if ($biker->shift_id !== $shift->id) {
            abort(404);
        }

        // Defense-in-depth: verify shift is still mutable
        if (! in_array($shift->status, [ShiftStatus::Draft, ShiftStatus::Open])) {
            abort(403);
        }

        $biker->update($request->validated());

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Dados do entregador atualizados.');
    }

    /**
     * Remove a biker from a shift.
     *
     * BR-05: Only Admin can remove bikers (enforced by middleware + policy).
     * BR-01: Only draft and open shifts allow removal.
     */
    public function destroy(Request $request, Shift $shift, ShiftBiker $biker)
    {
        $this->authorize('addBiker', $shift);

        // Verify the shift_biker belongs to this shift
        if ($biker->shift_id !== $shift->id) {
            abort(404);
        }

        // Verify shift is still mutable
        if (! in_array($shift->status, [ShiftStatus::Draft, ShiftStatus::Open])) {
            return back()->with('error', 'Não é possível remover entregadores de um turno encerrado.');
        }

        $biker->delete();

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Entregador removido do turno.');
    }
}
