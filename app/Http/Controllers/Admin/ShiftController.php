<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ShiftStatus;
use App\Exceptions\WorkflowLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\ConfirmCloseShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Services\ShiftCloseService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Shift::with('restaurant');

        $validStatuses = collect(ShiftStatus::cases())->map->value->toArray();

        if ($request->has('status') && in_array($request->status, $validStatuses)) {
            $query->where('status', $request->status);
        }

        $shifts = $query->orderBy('created_at', 'desc')->paginate(15);

        return view('shifts.index', compact('shifts'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $restaurants = Restaurant::where('active', true)->orderBy('name')->get();

        return view('shifts.create', compact('restaurants'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreShiftRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'draft';

        $shift = Shift::create($data);

        return redirect()->route('shifts.show', $shift)
            ->with('success', 'Turno criado com sucesso.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Shift $shift)
    {
        $shift->load('restaurant', 'shiftBikers.biker', 'shiftBikers.payment');

        return view('shifts.show', compact('shift'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Shift $shift)
    {
        $restaurants = Restaurant::where('active', true)->orderBy('name')->get();

        return view('shifts.edit', compact('shift', 'restaurants'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateShiftRequest $request, Shift $shift)
    {
        try {
            $shift->fill($request->validated());
            $shift->save();

            return redirect()->route('shifts.show', $shift)
                ->with('success', 'Turno atualizado com sucesso.');
        } catch (WorkflowLockedException) {
            return back()->with('error', 'Não é possível alterar o método de rastreamento.')
                ->withInput();
        }
    }

    /**
     * Phase 3A: Show close review page (GET).
     */
    public function reviewClose(Request $request, Shift $shift)
    {
        $this->authorize('reviewClose', $shift);

        if ($shift->status !== ShiftStatus::Open) {
            return redirect()->route('shifts.show', $shift)
                ->with('error', 'Somente turnos abertos podem ser encerrados.');
        }

        $reviewData = app(ShiftCloseService::class)->getReviewData($shift);

        return view('shifts.close-review', $reviewData);
    }

    /**
     * Phase 3A: Confirm and close shift with payout calculation (POST).
     */
    public function confirmClose(ConfirmCloseShiftRequest $request, Shift $shift)
    {
        try {
            app(ShiftCloseService::class)->closeAndCalculate($shift, $request->user());

            return redirect()->route('shifts.show', $shift)
                ->with('success', 'Turno encerrado. Pagamentos calculados com sucesso.');
        } catch (\RuntimeException) {
            return back()->with('error', 'Erro ao encerrar turno.');
        }
    }

    /**
     * Close the specified shift.
     */
    public function close(CloseShiftRequest $request, Shift $shift)
    {
        try {
            $shift->status = ShiftStatus::Closed;
            $shift->closed_at = now();
            $shift->save();

            return redirect()->route('shifts.show', $shift)
                ->with('success', 'Turno encerrado com sucesso.');
        } catch (WorkflowLockedException) {
            return back()->with('error', 'Erro ao encerrar turno.');
        } catch (\RuntimeException) {
            return back()->with('error', 'Transição de status inválida.');
        }
    }
}
