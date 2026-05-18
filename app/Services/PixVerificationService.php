<?php

namespace App\Services;

use App\Contracts\PixGatewayInterface;
use App\Enums\PaymentAuditAction;
use App\Enums\PixKeyType;
use App\Models\PaymentAuditLog;
use App\Models\PixKey;
use App\Models\User;
use Illuminate\Support\Str;

class PixVerificationService
{
    public function __construct(
        private readonly PixGatewayInterface $gateway,
    ) {}

    /**
     * Verify a PIX key against the gateway (BR-02).
     *
     * On success: sets is_verified=true, verified_at=now(), account_holder_name from gateway.
     * On gateway failure (success=false): throws RuntimeException, writes audit log.
     * On gateway exception: throws RuntimeException, does NOT write audit log.
     *
     * @throws \RuntimeException if key is already verified, biker missing, or gateway fails
     */
    public function verify(PixKey $pixKey, User $admin): PixKey
    {
        // Guard: key must not already be verified
        if ($pixKey->is_verified) {
            throw new \RuntimeException('PIX key is already verified');
        }

        // Guard: empty key value
        if (empty($pixKey->key_value)) {
            throw new \RuntimeException('PIX key value is empty');
        }

        // Guard: biker must exist
        if (! $pixKey->biker()->exists()) {
            throw new \RuntimeException('Biker not found');
        }

        // Call gateway — may throw RuntimeException (e.g. gateway down)
        $keyType = PixKeyType::from($pixKey->key_type);
        $response = $this->gateway->verifyKey($keyType, $pixKey->key_value);

        if (! $response->success) {
            // Write audit log — verification failed
            PaymentAuditLog::create([
                'payment_id' => null,
                'action' => PaymentAuditAction::VerifyPix,
                'transaction_ref' => 'pix-verify-fail-'.$pixKey->id.'-'.Str::uuid()->toString(),
                'error_message' => $response->error_message,
                'payload' => [
                    'pix_key_id' => $pixKey->id,
                    'biker_id' => $pixKey->biker_id,
                    'key_type' => $pixKey->key_type,
                    'key_value' => $pixKey->key_value,
                    'error_code' => $response->error_code,
                ],
            ]);

            throw new \RuntimeException('PIX verification failed: '.$response->error_message);
        }

        // Success — update pix_key
        $verifiedAt = now();
        $pixKey->is_verified = true;
        $pixKey->verified_at = $verifiedAt;
        $pixKey->account_holder_name = $response->account_holder_name;
        $pixKey->save();

        // Restore the Carbon instance (datetime cast truncates microseconds)
        $pixKey->setRawAttributes(
            array_merge($pixKey->getRawOriginal(), ['verified_at' => $verifiedAt]),
            true,
        );

        // Write audit log — verification succeeded
        PaymentAuditLog::create([
            'payment_id' => null,
            'action' => PaymentAuditAction::VerifyPix,
            'transaction_ref' => 'pix-verify-ok-'.$pixKey->id.'-'.Str::uuid()->toString(),
            'payload' => [
                'pix_key_id' => $pixKey->id,
                'biker_id' => $pixKey->biker_id,
                'key_type' => $pixKey->key_type,
                'key_value' => $pixKey->key_value,
                'account_holder_name' => $response->account_holder_name,
                'verified_by' => $admin->id,
            ],
        ]);

        return $pixKey;
    }

    /**
     * Unverify a previously verified PIX key.
     *
     * Resets is_verified=false, verified_at=null, account_holder_name=null.
     * Writes audit trail.
     *
     * @throws \RuntimeException if key is not currently verified
     */
    public function unverify(PixKey $pixKey, User $admin): PixKey
    {
        // Guard: key must be verified
        if (! $pixKey->is_verified) {
            throw new \RuntimeException('PIX key is not verified');
        }

        $pixKey->is_verified = false;
        $pixKey->verified_at = null;
        $pixKey->account_holder_name = null;
        $pixKey->save();

        // Write audit log
        PaymentAuditLog::create([
            'payment_id' => null,
            'action' => PaymentAuditAction::VerifyPix,
            'transaction_ref' => 'pix-unverify-'.$pixKey->id.'-'.Str::uuid()->toString(),
            'payload' => [
                'pix_key_id' => $pixKey->id,
                'biker_id' => $pixKey->biker_id,
                'key_type' => $pixKey->key_type,
                'key_value' => $pixKey->key_value,
                'unverified_by' => $admin->id,
            ],
        ]);

        return $pixKey;
    }
}
