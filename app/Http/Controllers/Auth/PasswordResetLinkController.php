<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NexoSso\NexoAuthMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Transition (NEXO_AUTH_MODE): an account already linked to Nexo ID
        // recovers its access there, so no local reset link goes out — with
        // the same answer, so the form still reveals nothing. (In sso mode the
        // whole flow 404s: EnsureLocalAuth.)
        $linked = NexoAuthMode::isTransition() && User::query()
            ->where('email', (string) $request->input('email'))
            ->whereNotNull('nexo_id_sub')
            ->exists();

        if (! $linked) {
            Password::sendResetLink($request->only('email'));
        }

        // Always the same response: don't leak which emails exist.
        return back()->with('status', __('If the email exists, we\'ve sent a link to reset the password.'));
    }
}
