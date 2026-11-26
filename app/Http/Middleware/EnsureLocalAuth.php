<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NexoSso\NexoAuthMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the local credential surfaces by auth mode (NEXO_AUTH_MODE, §2.7).
 * Ported from Nexo Short (ADR-003 §4 there) and extended to the three modes:
 *
 *   ->middleware(EnsureLocalAuth::class)              POST /login
 *   ->middleware(EnsureLocalAuth::class.':register')  registration
 *   ->middleware(EnsureLocalAuth::class.':password')  forgot / reset password
 *
 *               login        register                    password
 *   local       open         open                        open
 *   transition  open (*)     GET → Nexo ID sign-up,      open (*)
 *                            POST → 404
 *   sso         404          404                         404
 *
 * (*) only for accounts not linked to Nexo ID — the login/reset code checks
 * NexoAuthMode::refusesPasswordFor(). The GET /login page itself stays
 * reachable in every mode: it hosts the "Continue with Nexo ID" button.
 * (AC-AUTHMODE)
 */
final class EnsureLocalAuth
{
    public function handle(Request $request, Closure $next, string $surface = 'login'): Response
    {
        $open = match ($surface) {
            'register' => NexoAuthMode::allowsLocalRegistration(),
            'password' => NexoAuthMode::allowsPasswordReset(),
            default => NexoAuthMode::allowsPasswordLogin(),
        };

        if ($open) {
            return $next($request);
        }

        // An old "Create account" bookmark during the transition: the account
        // lives in Nexo ID now, so its sign-up is the right door.
        if ($surface === 'register' && $request->isMethod('GET') && NexoAuthMode::isTransition()) {
            $signUp = NexoAuthMode::registerUrl();
            if ($signUp !== null) {
                return redirect()->away($signUp);
            }
        }

        abort(404);
    }
}
