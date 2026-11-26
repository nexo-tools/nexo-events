<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NexoSso\NexoAuthMode;
use App\Services\NexoSso\NexoSsoApp;
use App\Services\NexoSso\NexoSsoFlows;
use App\Services\NexoSso\NexoSsoLinkConflictException;
use App\Services\NexoSso\NexoSsoLinkRefusedException;
use App\Services\NexoSso\NexoSsoNewAccount;
use App\Services\NexoSso\NexoSsoPendingLink;
use App\Services\NexoSso\NexoSsoRequest;
use App\Services\NexoSso\NexoSsoRevocations;
use App\Services\NexoSso\NexoSsoService;
use App\Services\NexoSso\NexoSsoSession;
use App\Services\NexoSso\NexoSsoUserResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The Nexo ID client endpoints (nexo-sso-client v2.2). Identical in every tool:
 * what differs per tool lives in config('nexo-sso.home') and the silent
 * exclusions (adaptation points), in NexoSsoUserResolver::newUser()/linked(),
 * and in the layout of the signed-out and new-account views.
 */
class NexoSsoController extends Controller
{
    /** Provider errors that mean "there is no Nexo ID session" after prompt=none. */
    private const NO_SESSION_ERRORS = ['login_required', 'interaction_required'];

    /** Re-validation retry after a provider hiccup (seconds). */
    private const RECHECK_RETRY = 300;

    /** Interactive sign-in ("Continue with Nexo ID"). Back to url.intended, else the panel. */
    public function redirect(Request $request, NexoSsoService $sso, NexoSsoFlows $flows): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled'), 404); // AC-CFG-1 (defense in depth)

        try {
            $url = $this->authorizeUrl($request, $sso, $flows, NexoSsoFlows::INTERACTIVE);
        } catch (Throwable) {
            // Provider unreachable: friendly failure, never a 500. (AC-DEGRADE-2)
            return $this->failed(__('Sign-in with Nexo ID is temporarily unavailable. Please try again later.'));
        }

        return redirect()->away($url);
    }

    /**
     * Silent attempt (OIDC prompt=none): same authorize request plus
     * `prompt=none`, marked in the session so it runs at most once per session
     * (the NexoSsoSilentLogin middleware checks the mark BEFORE redirecting
     * here — no retry loop). `?to=` is the page the attempt started from.
     * Every failure here is invisible by design: the user never asked for
     * anything, so on any hiccup they stay on that page. (AC-SILENT-1, AC-SILENT-2)
     */
    public function silent(Request $request, NexoSsoService $sso, NexoSsoFlows $flows): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && config('nexo-sso.silent'), 404);

        // Idempotent (the middleware already set it); also covers direct hits.
        $request->session()->put('nexo_sso.silent_attempted', true);

        $to = NexoSsoRequest::safePath($request->query('to'));

        try {
            $url = $this->authorizeUrl($request, $sso, $flows, NexoSsoFlows::SILENT, return: $to, prompt: 'none');
        } catch (Throwable) {
            // Provider unreachable: back to the page, no error UI.
            return $this->silentFallback($to);
        }

        return redirect()->away($url);
    }

    /**
     * Entry from another Nexo app: GET /auth/nexo/enter?to=/path. Signed in →
     * `to`. Guest → authorize WITHOUT prompt: 0 clicks with a live Nexo ID
     * session, the Nexo ID login otherwise — and back to `to` either way. `to`
     * must be a path of this origin; anything else lands on the panel.
     *
     * `&signup=1` (v2.2, «Crear cuenta»): the same, but the authorize carries
     * prompt=create, so a visitor without a Nexo ID session lands on Nexo ID's
     * sign-up and, once the email is verified, comes back here with a code —
     * an account in this tool, signed in, on `to`. A Nexo ID that doesn't know
     * `create` shows its login, which links to its sign-up and keeps the
     * authorize. Its flow waits up to an hour (NexoSsoFlows::SIGNUP_TTL): the
     * sign-up and the verification mail take longer than a sign-in. Any other
     * value of `signup` is ignored. (AC-SIGNUP)
     *
     * A signed-in password session (local or transition) is simply let in:
     * joining an account to Nexo ID is only ever explicit, through
     * nexo-sso.link. In sso mode such a session has no place (the guard closes
     * it), so it goes through Nexo ID like a guest. (AC-ENTER)
     */
    public function enter(Request $request, NexoSsoService $sso, NexoSsoFlows $flows): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled'), 404);

        $to = NexoSsoRequest::safePath($request->query('to')) ?? NexoSsoRequest::home();

        if (Auth::check() && (NexoSsoSession::isSso($request) || ! NexoAuthMode::isSso())) {
            return redirect()->to(url($to));
        }

        $signup = $request->query('signup') === '1';

        try {
            $url = $this->authorizeUrl($request, $sso, $flows, NexoSsoFlows::INTERACTIVE, return: $to,
                prompt: $signup ? 'create' : null, ttl: $signup ? NexoSsoFlows::SIGNUP_TTL : null);
        } catch (Throwable) {
            return $this->failed(__('Sign-in with Nexo ID is temporarily unavailable. Please try again later.'));
        }

        return redirect()->away($url);
    }

    /**
     * «Link my account» (POST + CSRF, signed in): joins this local account to a
     * Nexo ID through a `link` flow bound to the signed-in user, whatever email
     * either side has. In transition and in local mode (never in sso, where a
     * password session is closed anyway). `to` = where to come back. (AC-JOIN)
     */
    public function link(Request $request, NexoSsoService $sso, NexoSsoFlows $flows): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && ! NexoAuthMode::isSso(), 404);

        $to = NexoSsoRequest::safePath($request->input('to')) ?? NexoSsoRequest::home();

        if (NexoSsoSession::isSso($request)) {
            return redirect()->to(url($to)); // already a Nexo ID session: nothing to join
        }

        try {
            $url = $this->authorizeUrl($request, $sso, $flows, NexoSsoFlows::LINK, return: $to);
        } catch (Throwable) {
            NexoSsoSession::noticeError($request, __('Sign-in with Nexo ID is temporarily unavailable. Please try again later.'));

            return redirect()->to(url($to));
        }

        return redirect()->away($url);
    }

    /**
     * «First time in <App>?» (transition): a Nexo ID sign-in that matched no
     * account waits in the session; the person chooses between creating an
     * account and signing in with the one they already have. (AC-NEWACCOUNT)
     */
    public function newAccount(Request $request): Response|RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && NexoAuthMode::isTransition(), 404);

        $email = NexoSsoNewAccount::email($request);
        if ($email === null) {
            return redirect()->route('login'); // nothing waiting (or the 15 minutes passed)
        }

        return response()
            ->view('nexo-sso.new-account', ['email' => $email, 'app' => NexoSsoApp::name()])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * The choice on «First time in <App>?» (POST + CSRF). `create`: the account
     * is created from the waiting claims and the person is in. `existing`: the
     * claims are armed and the person goes to the password login — signing in
     * there within the window leads to «Link this account?», where the join is
     * confirmed (NexoSsoLinkOnPasswordLogin).
     */
    public function storeNewAccount(Request $request, NexoSsoUserResolver $resolver): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && NexoAuthMode::isTransition(), 404);

        $pending = NexoSsoNewAccount::pending($request);
        if ($pending === null) {
            return $this->failed(__('Your Nexo ID sign-in expired. Please try again.'));
        }

        if ($request->input('choice') === 'existing') {
            NexoSsoNewAccount::arm($request);
            if ($pending['return'] !== null && ! $request->session()->has('url.intended')) {
                $request->session()->put('url.intended', url($pending['return']));
            }

            return redirect()->route('login');
        }

        NexoSsoNewAccount::forget($request);

        try {
            // Someone may have joined this Nexo ID (or an account with its
            // verified email) in the meantime: that account wins over a new one.
            $user = $resolver->find($pending['claims']) ?? $resolver->create($pending['claims']);
        } catch (NexoSsoLinkRefusedException) {
            return $this->failed(__('An account with this email already exists. Verify your email on Nexo ID first.'));
        } catch (NexoSsoLinkConflictException $e) {
            return $this->failed($e->userMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('Sign-in with Nexo ID failed. Please try again.'));
        }

        return $this->signIn($request, $user, $pending['claims'], $pending['id_token'], $pending['return'], silent: false);
    }

    /**
     * Periodic re-validation, started by NexoSsoSessionGuard on a GET page
     * navigation: authorize with prompt=none, back to `to`. (AC-RECHECK)
     */
    public function recheck(Request $request, NexoSsoService $sso, NexoSsoFlows $flows): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled'), 404);

        $to = NexoSsoRequest::safePath($request->query('to')) ?? NexoSsoRequest::home();

        if ((int) config('nexo-sso.recheck_minutes') <= 0) {
            return redirect()->to(url($to)); // re-validation is off: nothing to do
        }

        // Other tabs navigating during this bounce wait for it instead of
        // starting their own. (NexoSsoSessionGuard)
        $request->session()->put(NexoSsoSession::RECHECK_STARTED_AT, now()->getTimestamp());

        try {
            $url = $this->authorizeUrl($request, $sso, $flows, NexoSsoFlows::RECHECK, return: $to, prompt: 'none');
        } catch (Throwable) {
            NexoSsoSession::deferRecheck($request, self::RECHECK_RETRY); // fail-open (R8)

            return redirect()->to(url($to));
        }

        return redirect()->away($url);
    }

    /**
     * Provider redirected back. Resolves by the mode saved with the state:
     * interactive or silent sign-in, or the re-validation of a signed-in
     * session. An unknown, replayed or foreign state never signs anyone in or
     * out. (AC-FLOW-2, AC-RECHECK)
     */
    public function callback(Request $request, NexoSsoService $sso, NexoSsoFlows $flows, NexoSsoUserResolver $resolver): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled'), 404); // AC-CFG-1 (defense in depth)

        $flow = $flows->pull($request, (string) $request->query('state', ''));

        if ($flow === null) {
            if (Auth::check()) {
                return redirect()->to($this->home()); // a stale tab's answer: nothing changes
            }

            return $this->failed(__('The sign-in request could not be validated. Please try again.'));
        }

        if ($flow['mode'] === NexoSsoFlows::RECHECK) {
            return $this->completeRecheck($request, $sso, $flow);
        }

        if ($flow['mode'] === NexoSsoFlows::LINK) {
            return $this->completeLink($request, $sso, $resolver, $flow);
        }

        // A new sign-in supersedes a «First time in <App>?» or a join left waiting.
        NexoSsoNewAccount::forget($request);
        NexoSsoPendingLink::forget($request);

        return $this->completeSignIn($request, $sso, $resolver, $flow, $flow['mode'] === NexoSsoFlows::SILENT);
    }

    /**
     * Local logout with RP-initiated (front-channel) central logout. Ends the
     * tool session first, then — when SSO is enabled and the provider advertises
     * an end_session_endpoint — hands the browser off to it so the Nexo ID
     * session ends too, and Nexo ID tells every other tool of this device over
     * the back channel. Nexo ID returns the browser to post_logout_redirect_uri
     * (default: this tool's signed-out screen). Best-effort: a provider hiccup
     * never blocks the local logout that already happened. (AC-LOGOUT-1)
     *
     * A password session (local or transition, not joined to Nexo ID in this
     * session) only ends here: there is no Nexo ID session of its own to end.
     * A guest — the page's session already gone — never reaches this: `auth`
     * sends it to the hint-less end_session (NexoSsoStaleLogout).
     */
    public function logout(Request $request, NexoSsoService $sso): RedirectResponse
    {
        $idTokenHint = (string) $request->session()->get(NexoSsoSession::ID_TOKEN, '');
        $governed = NexoSsoSession::isSso($request);

        // A logout must stick: the fresh session carries the silent mark, so
        // the next guest page doesn't sign the user straight back in. (AC-SILENT-5)
        NexoSsoSession::end($request);

        if (config('nexo-sso.enabled') && $governed) {
            try {
                $endSessionUrl = $sso->buildEndSessionUrl($idTokenHint !== '' ? $idTokenHint : null);
                if ($endSessionUrl !== null) {
                    return redirect()->away($endSessionUrl);
                }
            } catch (Throwable) {
                // Provider unreachable while building the URL: fall back to the
                // local post-logout page — the tool session is already gone.
            }
        }

        return redirect()->route('login');
    }

    /**
     * "You signed out of all of Nexo" (reason=signed_out) or "Your Nexo ID
     * session ended" (reason=ended). Never cached (the back button must not
     * bring a signed-in page back) and never indexed. (AC-SIGNEDOUT)
     */
    public function signedOut(Request $request): Response
    {
        abort_unless(config('nexo-sso.enabled'), 404);

        $reason = $request->query('reason') === 'ended' ? 'ended' : 'signed_out';

        return response()
            ->view('nexo-sso.signed-out', ['reason' => $reason])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Back-channel logout (OIDC Back-Channel Logout 1.0): Nexo ID POSTs a
     * signed logout_token server to server. Stateless (no session, no CSRF, no
     * cookies). 200 empty = revoked; 400 = not a valid token; 501 = switched
     * off; 503 = valid, but the revocation could not be stored (the provider
     * retries the same token, so its jti is released). Always no-store. (AC-BCL-C)
     */
    public function backchannel(Request $request, NexoSsoService $sso, NexoSsoRevocations $revocations): Response|JsonResponse
    {
        abort_unless(config('nexo-sso.enabled'), 404);

        $headers = ['Cache-Control' => 'no-store'];

        if (! config('nexo-sso.backchannel')) {
            return response()->json(['error' => 'backchannel_logout_disabled'], 501, $headers);
        }

        try {
            $claims = $sso->validateLogoutToken((string) $request->input('logout_token', ''));
        } catch (Throwable) {
            return response()->json(['error' => 'invalid_request'], 400, $headers);
        }

        $sid = is_string($claims['sid'] ?? null) ? $claims['sid'] : null;
        $sub = is_string($claims['sub'] ?? null) ? $claims['sub'] : null;

        try {
            $revocations->revoke($sid, $sub);
        } catch (Throwable $e) {
            report($e);
            $sso->releaseLogoutToken($claims);

            return response()->json(['error' => 'temporarily_unavailable'], 503, $headers);
        }

        return response('', 200, $headers);
    }

    /**
     * Interactive or silent sign-in. Interactive failures go to the login page
     * with a `nexo_sso` error; silent ones are invisible (back to where the
     * visitor was going).
     *
     * In transition, a Nexo ID that matches no account (neither by sub nor by
     * email) doesn't create one on the spot: the interactive sign-in asks
     * «First time in <App>?» first (/auth/nexo/new-account), and a silent one
     * just stays a guest — nobody gets a question they didn't ask for.
     *
     * @param  array{mode: string, verifier: string, nonce: string, return: ?string, user: ?string}  $flow
     */
    private function completeSignIn(Request $request, NexoSsoService $sso, NexoSsoUserResolver $resolver, array $flow, bool $silent): RedirectResponse
    {
        // OIDC error response — the provider declined without issuing a code.
        // After a silent attempt, `login_required`/`interaction_required` is the
        // NORMAL "no Nexo ID session" answer: stay guest, keep the once-per-
        // session mark, continue to the destination with no error UI. (AC-SILENT-2)
        if ($request->filled('error') || ! $request->filled('code')) {
            return $silent
                ? $this->silentFallback($flow['return'])
                : $this->failed(__('Sign-in with Nexo ID failed. Please try again.'));
        }

        try {
            $tokens = $sso->exchangeCode((string) $request->query('code'), $flow['verifier']);
            $idToken = (string) ($tokens['id_token'] ?? '');
            $claims = $sso->validateIdToken($idToken, $flow['nonce']); // AC-FLOW-3, AC-NONCE-1
            $user = $resolver->find($claims);

            if ($user === null && NexoAuthMode::isTransition()) {
                if ($silent) {
                    return $this->silentFallback($flow['return']);
                }

                NexoSsoNewAccount::hold($request, $claims, $idToken, $flow['return']);

                return redirect()->route('nexo-sso.new-account');
            }

            $user ??= $resolver->create($claims);
        } catch (NexoSsoLinkRefusedException) {
            return $silent
                ? $this->silentFallback($flow['return']) // they'll see it if they sign in interactively
                : $this->failed(__('An account with this email already exists. Verify your email on Nexo ID first.')); // AC-LINK-2
        } catch (NexoSsoLinkConflictException $e) {
            // The email matches an account joined to another Nexo ID. (AC-LINK-4)
            return $silent ? $this->silentFallback($flow['return']) : $this->failed($e->userMessage());
        } catch (Throwable $e) {
            // Invalid token or provider down mid-flow: safe error, no session. (AC-FLOW-3, AC-DEGRADE-2)
            report($e);

            return $silent
                ? $this->silentFallback($flow['return'])
                : $this->failed(__('Sign-in with Nexo ID failed. Please try again.'));
        }

        return $this->signIn($request, $user, $claims, $idToken, $flow['return'], $silent);
    }

    /**
     * Opens the tool session of a Nexo ID sign-in and sends the person on.
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     */
    private function signIn(Request $request, User $user, array $claims, string $idToken, ?string $return, bool $silent): RedirectResponse
    {
        // Tool-owned session, never a tool "remember me": Nexo ID governs how
        // long the person stays signed in. (AC-SESS-1)
        Auth::login($user);
        $request->session()->regenerate();

        // sub/sid for the guard, the raw id_token for the logout hint. (AC-LOGOUT-1)
        NexoSsoSession::establish($request, $claims, $idToken);

        // Silent: a deep link `auth` saved before (url.intended) wins over the
        // page the attempt started from. (AC-SILENT-6)
        if ($silent) {
            return redirect()->intended(url($return ?? NexoSsoRequest::home()));
        }

        if ($return !== null) {
            $request->session()->forget('url.intended');

            return redirect()->to(url($return));
        }

        return redirect()->intended($this->home());
    }

    /**
     * «Link my account» came back. Only for the user who asked (anyone else
     * signed in now, or nobody, is refused); the Nexo ID must not belong to
     * another account, nor this account to another Nexo ID. Then the account
     * is joined — whatever the emails — and the session becomes a Nexo ID one.
     * Unless Nexo ID vouched for this account's own email, the person first
     * confirms on /auth/nexo/link/confirm, seeing both emails: the Nexo ID
     * that answered with 0 clicks may be a session somebody else left open in
     * this browser. Every refusal leaves the local session as it was. (AC-JOIN)
     *
     * @param  array{mode: string, verifier: string, nonce: string, return: ?string, user: ?string}  $flow
     */
    private function completeLink(Request $request, NexoSsoService $sso, NexoSsoUserResolver $resolver, array $flow): RedirectResponse
    {
        $return = url($flow['return'] ?? NexoSsoRequest::home());
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->failed(__('The sign-in request could not be validated. Please try again.'));
        }

        if ($flow['user'] === null || ! hash_equals($flow['user'], (string) $user->getAuthIdentifier())) {
            return $this->linkFailed($request, $return, __('The sign-in request could not be validated. Please try again.'));
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->linkFailed($request, $return, __('Your account was not linked. Please try again.'));
        }

        try {
            $tokens = $sso->exchangeCode((string) $request->query('code'), $flow['verifier']);
            $idToken = (string) ($tokens['id_token'] ?? '');
            $claims = $sso->validateIdToken($idToken, $flow['nonce']);

            if ($resolver->needsConfirmation($user, $claims)) {
                $resolver->assertLinkable($user, $claims); // nothing to confirm if it can't happen
                NexoSsoPendingLink::hold($request, $user, $claims, $idToken, $flow['return']);

                return redirect()->route('nexo-sso.link.confirm');
            }

            $resolver->link($user, $claims);
        } catch (NexoSsoLinkConflictException $e) {
            return $this->linkFailed($request, $return, $e->userMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->linkFailed($request, $return, __('Your account was not linked. Please try again.'));
        }

        return $this->joined($request, $claims, $idToken, $return);
    }

    /**
     * «Link this account?» (GET, signed in): the join waiting for this user,
     * with both emails side by side — this account's and the Nexo ID's.
     * Nothing waiting (or the window passed) → back to the panel. (AC-JOIN-CONFIRM)
     */
    public function linkConfirm(Request $request): Response|RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && ! NexoAuthMode::isSso(), 404);

        $user = $request->user();
        $pending = NexoSsoPendingLink::for($request, $user);
        if ($pending === null || ! $user instanceof User) {
            return redirect()->to($this->home());
        }

        return response()
            ->view('nexo-sso.link-confirm', [
                'app' => NexoSsoApp::name(),
                'localEmail' => (string) $user->getAttribute('email'),
                'nexoEmail' => (string) ($pending['claims']['email'] ?? ''),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * The answer (POST + CSRF). `link`: joined now — if it still can be (the
     * Nexo ID may have been joined to another account meanwhile). Anything
     * else: nothing joined, the password session goes on. Single use either
     * way, and only for the user it was held for. (AC-JOIN-CONFIRM)
     */
    public function storeLinkConfirm(Request $request, NexoSsoUserResolver $resolver): RedirectResponse
    {
        abort_unless(config('nexo-sso.enabled') && ! NexoAuthMode::isSso(), 404);

        $user = $request->user();
        $pending = NexoSsoPendingLink::for($request, $user);
        NexoSsoPendingLink::forget($request);

        if ($pending === null || ! $user instanceof User) {
            return $this->linkFailed($request, $this->home(), __('Your Nexo ID sign-in expired. Please try again.'));
        }

        $return = url($pending['return'] ?? NexoSsoRequest::home());

        if ($request->input('choice') !== 'link') {
            return redirect()->to($return); // «Not now»: nothing joined, nothing to say
        }

        try {
            $resolver->link($user, $pending['claims']);
        } catch (NexoSsoLinkConflictException $e) {
            return $this->linkFailed($request, $return, $e->userMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->linkFailed($request, $return, __('Your account was not linked. Please try again.'));
        }

        return $this->joined($request, $pending['claims'], $pending['id_token'], $return);
    }

    /**
     * The account was just joined: the session becomes a Nexo ID one (a fresh
     * id, sub/sid/id_token) and the next page says so once.
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     */
    private function joined(Request $request, array $claims, string $idToken, string $return): RedirectResponse
    {
        $request->session()->regenerate();
        NexoSsoSession::establish($request, $claims, $idToken);
        NexoSsoSession::noticeLinked($request);

        return redirect()->to($return);
    }

    /**
     * Re-validation result: same person → renew the check and adopt the sid;
     * no Nexo ID session or another person → close the local session ("Your
     * Nexo ID session ended"); anything else (the provider misbehaving) keeps
     * the session and retries later — fail-open. (AC-RECHECK)
     *
     * @param  array{mode: string, verifier: string, nonce: string, return: ?string, user: ?string}  $flow
     */
    private function completeRecheck(Request $request, NexoSsoService $sso, array $flow): RedirectResponse
    {
        $return = url($flow['return'] ?? NexoSsoRequest::home());

        if (in_array((string) $request->query('error'), self::NO_SESSION_ERRORS, true)) {
            NexoSsoSession::end($request);

            return redirect()->route('nexo-sso.signed-out', ['reason' => 'ended']);
        }

        // Any other error, or no code at all: the provider misbehaved, not the
        // session. Keep it and try again later.
        if ($request->filled('error') || ! $request->filled('code')) {
            NexoSsoSession::deferRecheck($request, self::RECHECK_RETRY);

            return redirect()->to($return);
        }

        try {
            $tokens = $sso->exchangeCode((string) $request->query('code'), $flow['verifier']);
            $idToken = (string) ($tokens['id_token'] ?? '');
            $claims = $sso->validateIdToken($idToken, $flow['nonce']);
        } catch (Throwable $e) {
            report($e);
            NexoSsoSession::deferRecheck($request, self::RECHECK_RETRY);

            return redirect()->to($return);
        }

        $expected = NexoSsoSession::sub($request);
        if ($expected === null || ! hash_equals($expected, (string) ($claims['sub'] ?? ''))) {
            // Nexo ID is now signed in as somebody else: this session is not theirs.
            NexoSsoSession::end($request);

            return redirect()->route('nexo-sso.signed-out', ['reason' => 'ended']);
        }

        NexoSsoSession::renew($request, $claims, $idToken);

        return redirect()->to($return);
    }

    /** Starts a flow (state + PKCE + nonce + mode) and returns the provider URL. (AC-FLOW-1) */
    private function authorizeUrl(Request $request, NexoSsoService $sso, NexoSsoFlows $flows, string $mode, ?string $return = null, ?string $prompt = null, ?int $ttl = null): string
    {
        $flow = $ttl !== null ? $flows->start($request, $mode, $return, $ttl) : $flows->start($request, $mode, $return);

        return $sso->buildAuthorizeUrl($flow['state'], $flow['challenge'], $flow['nonce'], $prompt); // discovery may hit the network
    }

    /**
     * A silent attempt that didn't sign anyone in: back to the page it started
     * from (the public root for a direct hit — the panel would bounce a guest
     * straight into a sign-in). Never spends url.intended: a deep link saved
     * before stays for the real sign-in. (AC-SILENT-10)
     */
    private function silentFallback(?string $return): RedirectResponse
    {
        return redirect()->to(url($return ?? '/'));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['nexo_sso' => $message]);
    }

    /** «Link my account» didn't happen: back where it started, the session untouched, with the reason. */
    private function linkFailed(Request $request, string $return, string $message): RedirectResponse
    {
        NexoSsoSession::noticeError($request, $message);

        return redirect()->to($return);
    }

    private function home(): string
    {
        return url(NexoSsoRequest::home());
    }
}
