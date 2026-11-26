<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Http\Request;

/**
 * "First time in <App>?" (transition mode). A person signs in with a Nexo ID no
 * local account matches — neither by sub nor by email. Before creating an
 * account, the callback keeps the verified claims here and shows
 * /auth/nexo/new-account: «Create my account», or «I already have an account
 * with another email» — which arms the claims, so that signing in with that
 * account's password within WINDOW seconds offers to join it to this Nexo ID
 * (the NexoSsoLinkOnPasswordLogin listener → «Link this account?»). (AC-NEWACCOUNT)
 *
 * The claims live in the session, server side, never in a URL; they are single
 * use, and the window counts from the Nexo ID sign-in (arming doesn't extend it).
 */
final class NexoSsoNewAccount
{
    /** Session key of the pending claims. */
    public const SESSION_KEY = 'nexo_sso.new_account';

    /** How long the pending Nexo ID sign-in can still be used (seconds). */
    public const WINDOW = 900;

    /**
     * Keeps a verified Nexo ID sign-in that matched no account.
     *
     * @param  array<string, mixed>  $claims  the verified id_token claims
     */
    public static function hold(Request $request, array $claims, string $idToken, ?string $return): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'claims' => $claims,
            'id_token' => $idToken,
            'return' => $return,
            'at' => now()->getTimestamp(),
            'armed' => false,
        ]);
    }

    /**
     * The pending sign-in, or null when there is none or its window passed (an
     * expired one is forgotten here).
     *
     * @return array{claims: array<string, mixed>, id_token: string, return: ?string, at: int, armed: bool}|null
     */
    public static function pending(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! is_array($pending['claims'] ?? null) || ! is_numeric($pending['at'] ?? null)) {
            return null;
        }

        if (now()->getTimestamp() - (int) $pending['at'] > self::WINDOW) {
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
            'armed' => ($pending['armed'] ?? false) === true,
        ];
    }

    /**
     * «I already have an account with another email»: the next password
     * sign-in of this browser (inside the window) offers to join that account.
     */
    public static function arm(Request $request): void
    {
        if (self::pending($request) !== null) {
            $request->session()->put(self::SESSION_KEY.'.armed', true);
        }
    }

    /**
     * The pending sign-in, only when armed.
     *
     * @return array{claims: array<string, mixed>, id_token: string, return: ?string, at: int, armed: bool}|null
     */
    public static function armed(Request $request): ?array
    {
        $pending = self::pending($request);

        return $pending !== null && $pending['armed'] ? $pending : null;
    }

    /**
     * The login screen should open its password form: the person chose «I
     * already have an account with another email» and its window is still
     * open, in transition mode (which is never the effective mode with SSO
     * off). The login passes it to nexo-ui's <x-nexo-sign-in-options> so the
     * folded «Use email and password» starts open, and
     * <x-nexo-sso.link-notice> shows only then. (v2.2)
     */
    public static function awaitsPasswordSignIn(Request $request): bool
    {
        return NexoAuthMode::isTransition() && self::armed($request) !== null;
    }

    /** The Nexo ID email of the pending sign-in, for the screens that mention it. */
    public static function email(Request $request): ?string
    {
        $email = self::pending($request)['claims']['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
