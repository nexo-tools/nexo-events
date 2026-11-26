<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * «Cerrar sesión» from a page whose tool session is already gone: the CSRF
 * token no longer matches (419, TokenMismatchException) or the request arrives
 * as a guest. Showing "Page expired", or the login — which in sso mode signs
 * the person straight back in through Nexo ID — would make "Sign out" a lie.
 * Instead the browser goes to Nexo ID's end_session WITHOUT a hint, with this
 * client's client_id and post_logout_redirect_uri = the signed-out screen.
 *
 * Nexo ID answers a hint-less end_session with its own confirmation («¿Cerrar
 * sesión en todo Nexo?», a POST with CSRF), so this path can't be used to sign
 * anybody out with a forged form or link: nothing ends until the person
 * confirms there. For the same reason this tool's own session is ended here
 * only when the request is provably same-origin (Origin or Sec-Fetch-Site) —
 * a stale token in a tab of the same browser, not a cross-site form. (§0.1 item 2)
 *
 * Wiring (per tool):
 *   - bootstrap/app.php → withExceptions:
 *       $exceptions->render(fn (HttpException $e, Request $request) => NexoSsoStaleLogout::render($e, $request));
 *   - NexoAuthMode::guestRedirectUrl() already sends a guest's logout here.
 */
final class NexoSsoStaleLogout
{
    /** The logout routes this applies to: the central one and the tool's local one. */
    private const ROUTES = ['nexo-sso.logout', 'logout'];

    /** Is this a page's «Cerrar sesión» (a form POST to a logout route) with SSO on? */
    public static function applies(Request $request): bool
    {
        return config('nexo-sso.enabled')
            && $request->isMethod('POST')
            && ! $request->expectsJson()
            && $request->routeIs(self::ROUTES);
    }

    /**
     * The exception render callback: a CSRF mismatch on a logout route becomes
     * the hint-less end_session redirect; anything else keeps its usual page
     * (null = not handled here).
     */
    public static function render(Throwable $e, Request $request): ?RedirectResponse
    {
        $mismatch = $e instanceof TokenMismatchException || $e->getPrevious() instanceof TokenMismatchException;

        if (! $mismatch || ! self::applies($request)) {
            return null;
        }

        if ($request->hasSession() && self::isSameOrigin($request) && Auth::guard('web')->check()) {
            NexoSsoSession::end($request);
        }

        return redirect()->away(self::url());
    }

    /**
     * Nexo ID's end_session without a hint (client_id + post_logout_redirect_uri),
     * or this tool's login when the provider advertises none or can't be reached.
     */
    public static function url(): string
    {
        try {
            $url = app(NexoSsoService::class)->buildEndSessionUrl(null);
        } catch (Throwable) {
            $url = null;
        }

        return $url ?? route('login');
    }

    /**
     * Sent by this origin's own page: the browser says so (Sec-Fetch-Site,
     * Origin). A cross-site or sibling-subdomain form never qualifies.
     */
    private static function isSameOrigin(Request $request): bool
    {
        if (strtolower((string) $request->headers->get('Sec-Fetch-Site')) === 'same-origin') {
            return true;
        }

        $origin = (string) $request->headers->get('Origin');

        return $origin !== '' && strtolower(rtrim($origin, '/')) === strtolower($request->getSchemeAndHttpHost());
    }
}
