<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3C: Authorizes Admin to retry a failed payment. No body fields.
 *
 * AC-3C-35: Admin-only authorization.
 */
class RetryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift
            && $this->user()->can('retryPayment', $shift);
    }

    public function rules(): array
    {
        return [];
    }
}
