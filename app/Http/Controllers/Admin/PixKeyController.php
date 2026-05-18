<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Biker;
use App\Models\PixKey;
use App\Services\PixVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PixKeyController extends Controller
{
    public function __construct(
        private readonly PixVerificationService $verificationService,
    ) {}

    /**
     * List all PIX keys for a biker.
     * AC-4A-26: GET /admin/bikers/{biker}/pix-keys
     */
    public function index(Biker $biker): View
    {
        $pixKeys = $biker->pixKeys()->get();

        return view('admin.bikers.pix-keys', [
            'biker' => $biker,
            'pixKeys' => $pixKeys,
        ]);
    }

    /**
     * Verify a PIX key against the gateway.
     * AC-4A-28: POST /admin/pix-keys/{pixKey}/verify
     */
    public function verify(PixKey $pixKey): RedirectResponse
    {
        try {
            $this->verificationService->verify($pixKey, auth()->user());

            return back()->with('success', 'Chave PIX verificada com sucesso.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Unverify a previously verified PIX key.
     * AC-4A-31: POST /admin/pix-keys/{pixKey}/unverify
     */
    public function unverify(PixKey $pixKey): RedirectResponse
    {
        try {
            $this->verificationService->unverify($pixKey, auth()->user());

            return back()->with('success', 'Chave PIX desverificada com sucesso.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
