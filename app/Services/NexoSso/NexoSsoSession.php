<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The SSO keys of a tool session and the one way to end it. Set by the callback
 * (§2.4): nexo_sso.sub, nexo_sso.sid, nexo_sso.id_token,
 * nexo_sso.authenticated_at and nexo_sso.checked_at (unix seconds).
 */
final class NexoSsoSession
{
    public const SUB = 'nexo_sso.sub';

    public const SID = 'nexo_sso.sid';

    public const ID_TOKEN = 'nexo_sso.id_token';

    public const AUTHENTICATED_AT = 'nexo_sso.authenticated_at';

    public const CHECKED_AT = 'nexo_sso.checked_at';

    /** Unix time before which no re-validation bounce happens (provider hiccup). */
    public const RETRY_AT = 'nexo_sso.recheck_retry_at';

    /** Unix time a re-validation bounce last started (other tabs wait for it). */
    public const RECHECK_STARTED_AT = 'nexo_sso.recheck_started_at';

    /**
     * One-request message about "Link my account" (flashed): ['kind' => 'linked']
     * or ['kind' => 'error', 'message' => …]. The transition banner shows it.
     */
    public const NOTICE = 'nexo_sso.notice';

    /**
     * A fresh sign-in through Nexo ID. Call after Auth::login() + regenerate().
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     */
    public static function establish(Request $request, array $claims, string $idToken): void
    {
        $now = now()->getTimestamp();

        $request->session()->put([
            self::SUB => (string) ($claims['sub'] ?? ''),
            self::SID => self::sidFrom($claims),
            self::ID_TOKEN => $idToken,
            self::AUTHENTICATED_AT => $now,
            self::CHECKED_AT => $now,
        ]);
        $request->session()->forget([self::RETRY_AT, self::RECHECK_STARTED_AT]);
    }

    /**
     * A successful re-validation: same person, Nexo ID session still alive.
     * Adopts the sid (sessions opened before sid existed get one here) and
     * keeps the original authenticated_at — a revocation by `sub` issued after
     * the sign-in must still apply.
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     */
    public static function renew(Request $request, array $claims, string $idToken): void
    {
        $session = $request->session();
        $now = now()->getTimestamp();

        $session->put([
            self::SUB => (string) ($claims['sub'] ?? ''),
            self::SID => self::sidFrom($claims) ?? $session->get(self::SID),
            self::ID_TOKEN => $idToken,
            self::CHECKED_AT => $now,
        ]);

        if (! is_numeric($session->get(self::AUTHENTICATED_AT))) {
            $session->put(self::AUTHENTICATED_AT, $now);
        }

        $session->forget([self::RETRY_AT, self::RECHECK_STARTED_AT]);
    }

    /**
     * Was this session opened through Nexo ID? Sessions from before v2 carry
     * only the id_token (no sub): they count, and re-validate on their first
     * GET navigation.
     */
    public static function isSso(Request $request): bool
    {
        $session = $request->session();

        return (string) $session->get(self::SUB, '') !== '' || (string) $session->get(self::ID_TOKEN, '') !== '';
    }

    /** The Nexo ID subject of this session (falls back to the linked account). */
    public static function sub(Request $request): ?string
    {
        $sub = (string) $request->session()->get(self::SUB, '');
        if ($sub !== '') {
            return $sub;
        }

        $user = $request->user();
        $linked = $user instanceof Model ? (string) $user->getAttribute('nexo_id_sub') : '';

        return $linked !== '' ? $linked : null;
    }

    public static function sid(Request $request): ?string
    {
        $sid = (string) $request->session()->get(self::SID, '');

        return $sid !== '' ? $sid : null;
    }

    public static function authenticatedAt(Request $request): ?int
    {
        $at = $request->session()->get(self::AUTHENTICATED_AT);

        return is_numeric($at) ? (int) $at : null;
    }

    /** No re-validation bounce for the next $seconds (provider not answering well). */
    public static function deferRecheck(Request $request, int $seconds): void
    {
        $request->session()->put(self::RETRY_AT, now()->getTimestamp() + max(0, $seconds));
        $request->session()->forget(self::RECHECK_STARTED_AT);
    }

    /**
     * Ends the tool session for good: logout (which also forgets a local
     * remember-me cookie), a fresh session and the silent mark, so the next
     * public page doesn't silently sign the person back in. (AC-SILENT-5)
     */
    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('nexo_sso.silent_attempted', true);
    }

    /** The account was just joined to Nexo ID: tell the person on the next page. */
    public static function noticeLinked(Request $request): void
    {
        $request->session()->flash(self::NOTICE, ['kind' => 'linked']);
    }

    /** Joining the account didn't happen: say why on the next page. */
    public static function noticeError(Request $request, string $message): void
    {
        $request->session()->flash(self::NOTICE, ['kind' => 'error', 'message' => $message]);
    }

    /**
     * The flashed notice, if any.
     *
     * @return array{kind: string, message: string}|null
     */
    public static function notice(Request $request): ?array
    {
        $notice = $request->hasSession() ? $request->session()->get(self::NOTICE) : null;
        if (! is_array($notice) || ! in_array($notice['kind'] ?? null, ['linked', 'error'], true)) {
            return null;
        }

        return ['kind' => (string) $notice['kind'], 'message' => (string) ($notice['message'] ?? '')];
    }

    /** "Your Nexo ID session ended": the signed-out screen, or 401 for JSON. */
    public static function endedResponse(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['reason' => 'signed_out'], 401);
        }

        return redirect()->route('nexo-sso.signed-out', ['reason' => 'ended']);
    }

    /** @param  array<string, mixed>  $claims */
    private static function sidFrom(array $claims): ?string
    {
        $sid = $claims['sid'] ?? null;

        return is_string($sid) && $sid !== '' ? $sid : null;
    }
}
