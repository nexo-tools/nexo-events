<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NexoSso\NexoSsoRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Silent SSO trigger (OIDC prompt=none). On a guest PUBLIC page navigation,
 * hand the browser once per session to the silent authorize attempt: a visitor
 * with a live Nexo ID session comes back logged in without clicking anything;
 * anyone else comes straight back untouched (the provider answers
 * `login_required` without rendering any UI). (AC-SILENT-1..5)
 *
 * It never steals a destination (AC-SILENT-6): the page it starts from travels
 * with the flow (`?to=`), so `url.intended` — the deep link `auth` stored before
 * sending the guest to sign in — is neither replaced by this page nor spent on
 * a failed attempt: with a live Nexo ID session the person lands on that saved
 * deep link, without one they stay on this page (AC-SILENT-10). It never acts
 * on the auth screens (login,
 * register, password.*, verification.*, auth/nexo/*) nor on a request that is
 * carrying validation errors back to a form (AC-SILENT-7, AC-SILENT-8): those
 * belong to a person in the middle of signing in, and bouncing them would
 * swallow the error or the destination.
 *
 * Loop guard: the attempt is marked in the session BEFORE redirecting, and the
 * redirect-back lands in that same session, so a second attempt never fires —
 * neither on the bounce-back nor on any later pageload of the session.
 *
 * Bot/first-hit guard: only requests that already present this app's session
 * cookie are eligible. Crawlers and uptime monitors don't send cookies, so
 * public pages keep serving them plain 200s; a first-ever visit sets the
 * cookie and the attempt happens on the next navigation. (AC-SILENT-4)
 *
 * Install (per tool): append to the `web` middleware group. Standalone mode is
 * untouched — with SSO (or the silent switch) off this is a pass-through.
 */
final class NexoSsoSilentLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldAttempt($request)) {
            $request->session()->put('nexo_sso.silent_attempted', true);

            return redirect()->route('nexo-sso.silent', ['to' => NexoSsoRequest::currentPath($request)]);
        }

        return $next($request);
    }

    private function shouldAttempt(Request $request): bool
    {
        return config('nexo-sso.enabled')
            && config('nexo-sso.silent')
            && NexoSsoRequest::isPageNavigation($request)
            && $request->hasSession()
            && ! $request->session()->get('nexo_sso.silent_attempted', false)
            && ! $request->session()->has('errors')
            && auth()->guest()
            && $request->cookies->has((string) config('session.cookie'))
            && ! NexoSsoRequest::isAuthSurface($request)
            && ! NexoSsoRequest::isExcluded($request);
    }
}
