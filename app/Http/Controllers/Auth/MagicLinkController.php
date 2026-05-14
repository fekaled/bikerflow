<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\WhatsappServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class MagicLinkController extends Controller
{
    /**
     * Show the phone-based login form.
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Send a magic link to the given phone number.
     */
    public function sendMagicLink(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = $request->input('phone');

        $user = User::where('phone', $phone)->first();

        if ($user) {
            $signedUrl = URL::temporarySignedRoute(
                'auth.magic-link.verify',
                now()->addMinutes(config('auth.magic_link.expires', 15)),
                ['user' => $user->id, 'hash' => sha1($user->phone)]
            );

            app(WhatsappServiceInterface::class)->sendMagicLink($user->phone, $signedUrl);
        }

        return back()->with('status', 'If this phone is registered, a login link will be sent.');
    }

    /**
     * Verify the magic link and authenticate the user.
     */
    public function verifyMagicLink(Request $request, $user, $hash)
    {
        if (! $request->hasValidSignature()) {
            abort(401, 'Invalid or expired login link');
        }

        $userModel = User::findOrFail($user);

        if ($hash !== sha1($userModel->phone)) {
            abort(401, 'Invalid verification link');
        }

        $userModel->phone_verified_at = now();
        $userModel->save();

        Auth::login($userModel, true);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
