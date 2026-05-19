<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates incoming PIX webhook payload structure.
 *
 * Acceptance Criteria: AC-4C-16 through AC-4C-21
 *
 * @see docs/plans/phase-4c-pix-webhooks-async-status.md
 */
class PixWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Webhook endpoints are unauthenticated — authorization is handled
     * by the VerifyPixWebhookSignature middleware, not by user sessions.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // AC-4C-16: transaction_id is required string
            'transaction_id' => ['required', 'string'],
            // AC-4C-17: status is required string
            'status' => ['required', 'string'],
            // AC-4C-18: amount is nullable string
            'amount' => ['nullable', 'string'],
            // AC-4C-19: error_code is nullable string
            'error_code' => ['nullable', 'string'],
            // AC-4C-20: error_message is nullable string
            'error_message' => ['nullable', 'string'],
            // AC-4C-21: timestamp is nullable string
            'timestamp' => ['nullable', 'string'],
        ];
    }
}
