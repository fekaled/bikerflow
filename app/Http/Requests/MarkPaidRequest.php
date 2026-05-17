<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 3C: Authorizes Admin to mark a payment as paid. No body fields.
 *
 * AC-3C-17: Admin-only authorization.
 */
class MarkPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift
            && $this->user()->can('markPaid', $shift);
    }

    public function rules(): array
    {
        return [];
    }
}
