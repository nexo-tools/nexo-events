<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * The tool's auth mode (NEXO_AUTH_MODE), resolved to what is in effect right
 * now. Every gate — routes, login view, guard — asks here, so the three modes
 * can't drift between files. (AC-AUTHMODE)
 *
 *   local       local login + registration (+ Nexo ID when SSO is on)
 *   transition  Nexo ID first; passwords only for accounts not linked yet
 *   sso         Nexo ID only
 *
 * Effective mode: `local` whenever SSO is disabled (turning SSO off can never
 * lock an instance out); `transition` turns into `sso` the day after
 * NEXO_AUTH_TRANSITION_UNTIL. Unknown values (the old `both`) mean `local`.
 */
final class NexoAuthMode
{
    public const LOCAL = 'local';

    public const TRANSITION = 'transition';

    public const SSO = 'sso';

    public static function current(): string
    {
        if (! config('nexo-sso.enabled')) {
            return self::LOCAL;
        }

        $configured = strtolower(trim((string) config('nexo-sso.auth_mode', self::LOCAL)));

        if ($configured === self::SSO) {
            return self::SSO;
        }

        if ($configured === self::TRANSITION) {
            $cutover = self::cutover();

            return $cutover !== null && ! now()->lessThan($cutover) ? self::SSO : self::TRANSITION;
        }

        return self::LOCAL;
    }

    public static function isLocal(): bool
    {
        return self::current() === self::LOCAL;
    }

    public static function isTransition(): bool
    {
        return self::current() === self::TRANSITION;
    }

    public static function isSso(): bool
    {
        return self::current() === self::SSO;
    }

    /**
     * First moment the transition is over: the start of the day after
     * NEXO_AUTH_TRANSITION_UNTIL, in the app timezone. Null when unset or not a
     * YYYY-MM-DD date (the transition then has no end date).
     */
    public static function cutover(): ?CarbonImmutable
    {
        $until = trim((string) config('nexo-sso.transition_until', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $until, (string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }

        return $day instanceof CarbonImmutable && $day->format('Y-m-d') === $until ? $day->addDay() : null;
    }

    /** The cutover day, localized for the transition notices (null = no date). */
    public static function cutoverLabel(): ?string
    {
        return self::cutover()?->locale(app()->getLocale())->isoFormat('LL');
    }

    /** POST /login with a local password. */
    public static function allowsPasswordLogin(): bool
    {
        return ! self::isSso();
    }

    /** In transition, an account already linked to Nexo ID must use Nexo ID. */
    public static function refusesPasswordFor(?string $nexoIdSub): bool
    {
        return self::isTransition() && $nexoIdSub !== null && $nexoIdSub !== '';
    }

    /** The tool's own "remember me" (a credential Nexo ID can't revoke): local mode only. */
    public static function allowsRemember(): bool
    {
        return self::isLocal();
    }

    public static function allowsLocalRegistration(): bool
    {
        return self::isLocal();
    }

    /** Forgot/reset password: not in sso; in transition only for unlinked accounts (the caller filters). */
    public static function allowsPasswordReset(): bool
    {
        return ! self::isSso();
    }

    /** Nexo ID's own sign-up, where "Create account" points in transition and sso. */
    public static function registerUrl(): ?string
    {
        $issuer = (string) config('nexo-sso.issuer', '');

        return config('nexo-sso.enabled') && $issuer !== '' ? $issuer.'/register' : null;
    }

    /**
     * Where `auth` sends a guest (bootstrap/app.php → redirectGuestsTo). In sso
     * mode a deep link goes straight through Nexo ID and comes back to itself,
     * without ever showing /login. (§2.7) A guest's «Cerrar sesión» goes to Nexo
     * ID's hint-less end_session in every mode while SSO is on.
     */
    public static function guestRedirectUrl(Request $request): string
    {
        // «Cerrar sesión» from a page whose session is already gone: Nexo ID's
        // end_session (it confirms first), never a login that would sign the
        // person straight back in. (NexoSsoStaleLogout)
        if (NexoSsoStaleLogout::applies($request)) {
            return NexoSsoStaleLogout::url();
        }

        if (! self::isSso()) {
            return route('login');
        }

        $to = NexoSsoRequest::isPageNavigation($request) ? NexoSsoRequest::currentPath($request) : null;

        return route('nexo-sso.enter', $to !== null ? ['to' => $to] : []);
    }
}
