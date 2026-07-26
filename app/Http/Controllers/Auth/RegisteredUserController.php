<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        // Organizers are plain users; attendees never get an account (email-only, ADR-003).
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        // Queues the verification mail (User is MustVerifyEmail). Publishing is
        // blocked until it is confirmed (ADR-007 §1); everything else works now.
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
