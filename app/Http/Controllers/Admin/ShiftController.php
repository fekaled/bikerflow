<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ShiftStatus;
use App\Exceptions\WorkflowLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\ConfirmCloseShiftRequest;
use App\Http\Requests\MarkFailedRequest;
use App\Http\Requests\MarkPaidRequest;
use App\Http\Requests\RetryPaymentRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\Shift;
use App\Services\PaymentReleaseService;
use App\Services\PaymentSettlementService;
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
     * Phase 3B: Show payment review page for a closed/approved shift (GET).
     */
    public function reviewPayments(Request $request, Shift $shift)
    {
        $this->authorize('reviewPayments', $shift);

        if (! in_array($shift->status, [ShiftStatus::Closed, ShiftStatus::Approved])) {
            return redirect()->route('shifts.show', $shift)
                ->with('error', 'Somente turnos encerrados podem ter pagamentos revisados.');
        }

        $reviewData = app(PaymentReleaseService::class)->getPaymentReviewData($shift);

        return view('shifts.payment-review', $reviewData);
    }

    /**
     * Phase 3B: Release a single payment (POST).
     */
    public function releasePayment(Request $request, Shift $shift, Payment $payment)
    {
        $this->authorize('releasePayment', $shift);

        // Validate that payment belongs to this shift
        if ($payment->shiftBiker->shift_id !== $shift->id) {
            abort(404);
        }

        try {
            app(PaymentReleaseService::class)->releasePayment($payment, $request->user());

            return redirect()->route('shifts.payments.review', $shift)
                ->with('success', 'Pagamento liberado com sucesso.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 3B: Batch release all eligible payments (POST).
     */
    public function releaseAllPayments(Request $request, Shift $shift)
    {
        $this->authorize('releasePayment', $shift);

        if ($shift->status !== ShiftStatus::Closed && $shift->status !== ShiftStatus::Approved) {
            return back()->with('error', 'Somente turnos encerrados podem ter pagamentos liberados.');
        }

        $results = app(PaymentReleaseService::class)->releaseAllEligiblePayments($shift, $request->user());

        $message = count($results['released']).' pagamentos liberados.';
        if (count($results['blocked']) > 0) {
            $message .= ' '.count($results['blocked']).' pagamentos bloqueados.';
        }

        return redirect()->route('shifts.payments.review', $shift)
            ->with('success', $message);
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

    // ========================================================================
    // Phase 3C: Payment Settlement
    // ========================================================================

    /**
     * Phase 3C: Show per-shift payment status dashboard (GET).
     */
    public function paymentStatus(Request $request, Shift $shift)
    {
        $this->authorize('paymentStatus', $shift);

        if (! in_array($shift->status, [ShiftStatus::Approved, ShiftStatus::Paid])) {
            return redirect()->route('shifts.show', $shift)
                ->with('error', 'Somente turnos aprovados ou pagos podem ter status revisado.');
        }

        $data = app(PaymentSettlementService::class)->getSettlementData($shift);

        return view('shifts.payment-status', $data);
    }

    /**
     * Phase 3C: Mark a processing payment as paid (POST).
     */
    public function markPaid(MarkPaidRequest $request, Shift $shift, Payment $payment)
    {
        $this->assertPaymentBelongsToShift($payment, $shift);

        try {
            app(PaymentSettlementService::class)->markPaid($payment, $request->user());

            return back()->with('success', 'Pagamento marcado como pago.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()], 'payment')
                ->setStatusCode(422);
        }
    }

    /**
     * Phase 3C: Mark a processing payment as failed (POST).
     */
    public function markFailed(MarkFailedRequest $request, Shift $shift, Payment $payment)
    {
        $this->assertPaymentBelongsToShift($payment, $shift);

        try {
            app(PaymentSettlementService::class)->markFailed(
                $payment, $request->user(), $request->validated('failure_reason')
            );

            return back()->with('success', 'Pagamento marcado como falha.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()], 'payment')
                ->setStatusCode(422);
        }
    }

    /**
     * Phase 3C: Retry a failed payment (POST).
     */
    public function retryPayment(RetryPaymentRequest $request, Shift $shift, Payment $payment)
    {
        $this->assertPaymentBelongsToShift($payment, $shift);

        try {
            app(PaymentSettlementService::class)->retry($payment, $request->user());

            return back()->with('success', 'Pagamento reenviado para processamento.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()], 'payment')
                ->setStatusCode(422);
        }
    }

    /**
     * Verify that a payment belongs to the given shift. Abort 404 if not.
     */
    private function assertPaymentBelongsToShift(Payment $payment, Shift $shift): void
    {
        if ($payment->shiftBiker->shift_id !== $shift->id) {
            abort(404);
        }
    }
}
