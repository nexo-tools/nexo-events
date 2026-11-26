<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\NexoSso\NexoAuthMode;
use App\Services\NexoSso\NexoSsoRequest;
use App\Services\NexoSso\NexoSsoRevocations;
use App\Services\NexoSso\NexoSsoService;
use App\Services\NexoSso\NexoSsoSession;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nexo ID controls the tool session (§2.4). For a signed-in request:
 *
 *  1. Revoked — Nexo ID ended this session over the back channel (by sid, or by
 *     sub after this session signed in) → sign out; HTML goes to "Your Nexo ID
 *     session ended", JSON gets 401 {"reason":"signed_out"}. (AC-BCL-C)
 *  2. Re-validation due — the last check is older than NEXO_SSO_RECHECK_MINUTES
 *     (or missing: sessions from before v2) and this is a GET page navigation
 *     outside /auth/nexo and the silent exclusions → remember the URL and
 *     bounce once through /auth/nexo/recheck (prompt=none), which comes back to
 *     the same URL. NEVER a POST, NEVER JSON: forms and the door scanner are
 *     never redirected. (AC-RECHECK)
 *  3. Fail-open — before bouncing, a cached probe asks whether Nexo ID answers;
 *     if not, nobody is bounced and it is retried later. (R8)
 *
 * In sso mode a session Nexo ID does not govern (a local password login, a
 * local remember-me) is closed and sent through /auth/nexo/enter?to=<this URL>:
 * with a live Nexo ID session that is 0 clicks. In transition mode the same
 * happens to such a session of an account already linked to Nexo ID (it may
 * no longer use its password, §2.7); unlinked accounts keep theirs until the
 * cutover. On the public surfaces of the silent exclusions it is just closed,
 * and the page is served as a guest.
 *
 * Install (per tool): append to the `web` middleware group, after the session
 * (appending does that). With SSO disabled this is a pass-through.
 */
final class NexoSsoSessionGuard
{
    /** While a re-validation bounce is in flight, other tabs don't start their own. */
    private const IN_FLIGHT_SECONDS = 30;

    public function __construct(
        private readonly NexoSsoService $sso,
        private readonly NexoSsoRevocations $revocations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nexo-sso.enabled')
            || ! $request->hasSession()
            || $request->is('auth/nexo', 'auth/nexo/*')
            || Auth::guard('web')->guest()) {
            return $next($request);
        }

        if (! NexoSsoSession::isSso($request)) {
            if (! $this->mustBeGoverned($request)) {
                return $next($request);
            }

            // A public surface (storefront, bio page) is served to the visitor
            // as a guest instead of pushing them to the Nexo ID login.
            if (NexoSsoRequest::isExcluded($request)) {
                NexoSsoSession::end($request);

                return $next($request);
            }

            return $this->closeUngoverned($request);
        }

        if (config('nexo-sso.backchannel') && $this->revocations->isRevoked(
            NexoSsoSession::sid($request),
            NexoSsoSession::sub($request),
            NexoSsoSession::authenticatedAt($request),
        )) {
            NexoSsoSession::end($request);

            return NexoSsoSession::endedResponse($request);
        }

        if ($this->recheckDue($request) && $this->sso->isReachable()) {
            return redirect()->route('nexo-sso.recheck', ['to' => NexoSsoRequest::currentPath($request)]);
        }

        return $next($request);
    }

    private function recheckDue(Request $request): bool
    {
        $minutes = (int) config('nexo-sso.recheck_minutes', 15);

        if ($minutes <= 0
            || ! NexoSsoRequest::isPageNavigation($request)
            || NexoSsoRequest::isAuthSurface($request)
            || NexoSsoRequest::isExcluded($request)) {
            return false;
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        $retryAt = $session->get(NexoSsoSession::RETRY_AT);
        if (is_numeric($retryAt) && (int) $retryAt > $now) {
            return false;
        }

        $startedAt = $session->get(NexoSsoSession::RECHECK_STARTED_AT);
        if (is_numeric($startedAt) && $now - (int) $startedAt < self::IN_FLIGHT_SECONDS) {
            return false;
        }

        $checkedAt = $session->get(NexoSsoSession::CHECKED_AT);

        return ! is_numeric($checkedAt) || $now - (int) $checkedAt >= $minutes * 60;
    }

    /** Must this session be governed by Nexo ID? sso: always; transition: once the account is linked. */
    private function mustBeGoverned(Request $request): bool
    {
        if (NexoAuthMode::isSso()) {
            return true;
        }

        $user = $request->user();

        return NexoAuthMode::isTransition()
            && $user instanceof Model
            && (string) $user->getAttribute('nexo_id_sub') !== '';
    }

    /**
     * A credential Nexo ID doesn't govern is closed. A page navigation goes
     * through enter and comes back to itself (0 clicks with a live Nexo ID
     * session); anything else — a form POST, an old tab's "Sign out" — ends on
     * the signed-out screen: re-entering would sign the person straight back
     * in, and there is no page to return to. JSON gets 401.
     */
    private function closeUngoverned(Request $request): Response
    {
        $to = NexoSsoRequest::isPageNavigation($request) ? NexoSsoRequest::currentPath($request) : null;

        NexoSsoSession::end($request);

        if ($to === null) {
            return NexoSsoSession::endedResponse($request);
        }

        return redirect()->route('nexo-sso.enter', ['to' => $to]);
    }
}
