<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * A join waiting for its owner's «Link my account» click (nexo-sso-client
 * v2.1). Nexo ID vouched for an identity whose email is not this account's
 * own, so nothing is joined until the person signed in here sees BOTH emails
 * on /auth/nexo/link/confirm and confirms (POST + CSRF). That is what keeps a
 * Nexo ID session left open on a shared computer — or a «First time» choice
 * somebody else made in that browser — from taking an account over with the
 * owner's own password sign-in. (AC-JOIN-CONFIRM)
 *
 * Bound to the signed-in user who will confirm: anybody else signed in later
 * in this browser never sees it. Server side, in the session (never in a
 * URL), single use, WINDOW seconds from the moment Nexo ID vouched for it.
 */
final class NexoSsoPendingLink
{
    /** Session key of the waiting join. */
    public const SESSION_KEY = 'nexo_sso.pending_link';

    /** How long the confirmation stays possible (seconds), as «First time». */
    public const WINDOW = NexoSsoNewAccount::WINDOW;

    /**
     * Keeps a verified Nexo ID identity for $user to confirm.
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     * @param  int|null  $at  when Nexo ID vouched for it (default: now)
     */
    public static function hold(Request $request, Authenticatable $user, array $claims, string $idToken, ?string $return, ?int $at = null): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user' => (string) $user->getAuthIdentifier(),
            'claims' => $claims,
            'id_token' => $idToken,
            'return' => $return,
            'at' => $at ?? now()->getTimestamp(),
        ]);
    }

    /**
     * The join waiting for $user, or null: none, its window passed, or it
     * belongs to somebody else (both are forgotten here).
     *
     * @return array{claims: array<string, mixed>, id_token: string, return: ?string, at: int}|null
     */
    public static function for(Request $request, ?Authenticatable $user): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::SESSION_KEY);
        if ($pending === null) {
            return null;
        }

        if (! is_array($pending)
            || ! is_array($pending['claims'] ?? null)
            || ! is_numeric($pending['at'] ?? null)
            || ! is_string($pending['user'] ?? null)
            || $user === null
            || ! hash_equals($pending['user'], (string) $user->getAuthIdentifier())
            || now()->getTimestamp() - (int) $pending['at'] > self::WINDOW) {
            self::forget($request);

            return null;
        }

        /** @var array<string, mixed> $claims */
        $claims = $pending['claims'];
        $return = $pending['return'] ?? null;

        return [
            'claims' => $claims,
            'id_token' => (string) ($pending['id_token'] ?? ''),
            'return' => is_string($return) ? $return : null,
            'at' => (int) $pending['at'],
        ];
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
