<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Every decision the shared chrome takes, in one place (chrome v2, 2026-11-26).
 *
 * Copy to app/Support/NexoChrome.php. IDENTICAL IN THE 6 APPS: what differs per
 * app lives in config, never here —
 *   - config/nexo-ecosystem.php  which tool this is (`current`), names, marks, entry
 *   - config/nexo.php → chrome   the panel route (wordmark + «Tu panel») and,
 *                                opt-in, the app's own account page
 *                                (`account_route`, label `nexo.app.account`)
 *   - config/nexo.php            `allow_registration` (optional; false closes
 *                                the local sign-up, so «Crear cuenta» goes away)
 *   - lang/{es,en,pt}/nexo.php   the panel's own name (`nexo.app.panel`)
 *   - config/nexo-sso.php        SSO on/off, issuer, the auth mode and `home`
 *                                (where «Crear cuenta» comes back to)
 *
 * Why a class and not @php blocks: the header, the account menu, the phone sheet,
 * the switcher and the login card all answer the same questions («where does
 * "Iniciar sesión" go?», «what does the logout form post to?»). Answered five
 * times they drift five ways — exactly what the 2026-11-24 audit found (the
 * account lived in 5 different shapes across 6 apps).
 *
 * Route names come from the sso-client template (CONTRATO-F3 §2.5) and are always
 * checked with Route::has(): an app that has not ported session v2 yet, or runs
 * standalone (NEXO_SSO_ENABLED=false), simply falls back to its local routes.
 */
final class NexoChrome
{
    public const MODES = ['local', 'transition', 'sso'];

    /** Registry key of this app (nexotools, nexoid, …), or null for a bare self-host. */
    public static function current(): ?string
    {
        $current = config('nexo-ecosystem.current');

        return is_string($current) && $current !== '' ? $current : null;
    }

    /**
     * One registry entry (this app's by default).
     *
     * @return array<string, mixed>
     */
    public static function tool(?string $key = null): array
    {
        $key ??= self::current();
        $tools = (array) config('nexo-ecosystem.tools', []);
        $tool = $key !== null ? ($tools[$key] ?? null) : null;

        return is_array($tool) ? $tool : [];
    }

    /** «Nexo Tools»: the registry name, never APP_NAME (prod had «NexoTools»). */
    public static function brand(): string
    {
        $name = self::tool()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }

    public static function mark(): string
    {
        $mark = self::tool()['mark'] ?? null;

        return is_string($mark) && $mark !== '' ? $mark : '/favicon.svg';
    }

    /** Tab title of an inner page: «<Página> — Nexo <App>» (STANDARD, «Títulos»). */
    public static function pageTitle(?string $page = null): string
    {
        $page = trim((string) $page);

        return $page === '' ? self::brand() : $page.' — '.self::brand();
    }

    /** Tab title of the landing: «Nexo <App> — <tagline>». */
    public static function homeTitle(?string $tagline = null): string
    {
        $tagline = trim((string) ($tagline ?? self::tagline(self::current() ?? '')));

        return $tagline === '' ? self::brand() : self::brand().' — '.$tagline;
    }

    /**
     * A tool's one-line description in the current language. The registry keeps
     * the Spanish tagline as the fallback; the translations live in
     * lang/{es,en,pt}/nexo.php under nexo.ecosystem.<key>.
     *
     * @param  array<string, mixed>|null  $tool
     */
    public static function tagline(string $key, ?array $tool = null): string
    {
        if ($key !== '' && Lang::has("nexo.ecosystem.{$key}")) {
            return (string) __("nexo.ecosystem.{$key}");
        }

        $tool ??= self::tool($key);

        return (string) ($tool['tagline'] ?? '');
    }

    public static function ssoEnabled(): bool
    {
        return (bool) config('nexo-sso.enabled');
    }

    /** The Nexo ID base URL, without a trailing slash ('' when unset). */
    public static function issuer(): string
    {
        return rtrim((string) config('nexo-sso.issuer', ''), '/');
    }

    /**
     * NEXO_AUTH_MODE (CONTRATO-F3 §2.7): local | transition | sso, as in effect now.
     *
     * The mode is owned by the sso-client template: when
     * App\Services\NexoSso\NexoAuthMode exists (session v2 ported), it decides.
     * Until then this mirrors it exactly — same keys (`nexo-sso.auth_mode`,
     * `nexo-sso.transition_until`), local whenever SSO is off, and a transition
     * that turns into sso at the start of the day after
     * NEXO_AUTH_TRANSITION_UNTIL. LoginNexoIdFirstTest pins both readings.
     */
    public static function authMode(): string
    {
        $owner = 'App\\Services\\NexoSso\\NexoAuthMode';
        if (class_exists($owner)) {
            $mode = (string) $owner::current();

            return in_array($mode, self::MODES, true) ? $mode : 'local';
        }

        if (! self::ssoEnabled()) {
            return 'local';
        }

        $mode = strtolower(trim((string) config('nexo-sso.auth_mode', 'local')));

        if ($mode === 'transition') {
            $cutover = self::cutover();

            return $cutover !== null && ! now()->lessThan($cutover) ? 'sso' : 'transition';
        }

        return $mode === 'sso' ? 'sso' : 'local';
    }

    /**
     * First moment the transition is over (start of the day after
     * NEXO_AUTH_TRANSITION_UNTIL, app timezone), or null without a valid date.
     */
    public static function cutover(): ?CarbonImmutable
    {
        $owner = 'App\\Services\\NexoSso\\NexoAuthMode';
        if (class_exists($owner)) {
            $cutover = $owner::cutover();

            return $cutover instanceof CarbonImmutable ? $cutover : null;
        }

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

    /**
     * Whether the app's own password form offers «Recordarme». That is a
     * credential Nexo ID cannot revoke, so it only exists in `local` mode; in
     * `transition` and `sso` how long a person stays signed in is Nexo ID's
     * call (CONTRATO-F3 §2.7). Standalone (SSO off) it is always offered.
     *
     * Owned by the sso-client template like the mode itself: with session v2
     * ported, NexoAuthMode::allowsRemember() decides; until then, local mode.
     * The login view hides the checkbox with this, and the login request must
     * ask the same question before passing `remember` (the form is not a guard).
     */
    public static function allowsRemember(): bool
    {
        if (! self::ssoEnabled()) {
            return true;
        }

        $owner = 'App\\Services\\NexoSso\\NexoAuthMode';
        if (class_exists($owner)) {
            return (bool) $owner::allowsRemember();
        }

        return self::authMode() === 'local';
    }

    /** The last day passwords still work in transition, localized («31 de enero de 2027»). */
    public static function transitionLastDay(): ?string
    {
        return self::cutover()?->subDay()->locale(app()->getLocale())->isoFormat('LL');
    }

    /**
     * Where «Iniciar sesión» goes. Mode sso: straight to Nexo ID through the
     * entry route (0 clicks when Nexo ID already has a session). Otherwise the
     * local /login, which shows «Continuar con Nexo ID» first when SSO is on.
     */
    public static function signInUrl(?string $to = null): ?string
    {
        if (self::authMode() === 'sso') {
            if (Route::has('nexo-sso.enter')) {
                return route('nexo-sso.enter', $to !== null ? ['to' => $to] : []);
            }
            if (Route::has('nexo-sso.redirect')) {
                return route('nexo-sso.redirect');
            }
        }

        return Route::has('login') ? route('login') : null;
    }

    /** «Continuar con Nexo ID»: the interactive authorize, or null with SSO off. */
    public static function ssoSignInUrl(): ?string
    {
        if (! self::ssoEnabled()) {
            return null;
        }

        foreach (['nexo-sso.redirect', 'nexo-sso.enter'] as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }

    /**
     * «Crear cuenta» (v2.2, «alta que vuelve a la tool»).
     *
     * In sso and transition: the entry route with `signup=1` and this app's
     * home as `to` — the tool asks Nexo ID for its sign-up screen
     * (prompt=create), and once the account is created and its email verified
     * the authorize goes on and the person lands back in THIS tool, signed in.
     * Before v2.2 it linked Nexo ID's /register, and the person stayed in Nexo
     * ID for good. An sso-client without the entry route falls back to that
     * /register; one that predates `signup` ignores it and goes through Nexo
     * ID's login, which offers «Crear cuenta» and keeps the way back.
     *
     * In local: the local sign-up, unless the instance closed it
     * (NEXO_ALLOW_REGISTRATION=false, `nexo.allow_registration`), where the
     * route answers 404 and the link would be a dead end — then nothing.
     */
    public static function signUpUrl(): ?string
    {
        if (self::authMode() !== 'local') {
            if (Route::has('nexo-sso.enter')) {
                return route('nexo-sso.enter', ['to' => self::ssoHome(), 'signup' => 1]);
            }
            if (self::issuer() !== '') {
                return self::issuer().'/register';
            }
        }

        // The same reading as the gate that closes the route (nexoshort's
        // EnsureRegistrationOpen: `(bool) config(…)`), so the link and the
        // route never disagree; an app without the key keeps it open.
        if (! (bool) config('nexo.allow_registration', true)) {
            return null;
        }

        return Route::has('register') ? route('register') : null;
    }

    /**
     * Where this app sends a person once signed in through Nexo ID
     * (`nexo-sso.home`, the sso-client's adaptation point): a same-origin path,
     * '/' otherwise. With session v2 ported the sso-client answers
     * (NexoSsoRequest::home(), the same check its entry route applies to `to`);
     * until then the same rule, minus the /auth/nexo/* exclusion.
     */
    public static function ssoHome(): string
    {
        $owner = 'App\\Services\\NexoSso\\NexoSsoRequest';
        if (class_exists($owner)) {
            return (string) $owner::home();
        }

        $home = config('nexo-sso.home', '/');

        return is_string($home) && str_starts_with($home, '/') && ! str_starts_with($home, '//')
            && ! str_contains($home, '\\') && preg_match('/[\x00-\x1F\x7F]/', $home) !== 1
            ? $home
            : '/';
    }

    /** What the logout form posts to: the central logout when SSO is on (§5.2). */
    public static function logoutUrl(): ?string
    {
        if (self::ssoEnabled() && Route::has('nexo-sso.logout')) {
            return route('nexo-sso.logout');
        }

        return Route::has('logout') ? route('logout') : null;
    }

    /** «Tu cuenta Nexo ID» (<issuer>/profile). Not shown in Nexo ID itself, nor standalone. */
    public static function nexoIdAccountUrl(): ?string
    {
        if (self::current() === 'nexoid' || ! self::ssoEnabled() || self::issuer() === '') {
            return null;
        }

        return self::issuer().'/profile';
    }

    public static function panelRoute(): string
    {
        return (string) config('nexo.chrome.panel_route', 'dashboard');
    }

    /** This app's panel, where the wordmark goes with a session (D9: routes unchanged). */
    public static function panelUrl(): string
    {
        return Route::has(self::panelRoute()) ? route(self::panelRoute()) : url('/');
    }

    /** «Tus herramientas», «Tus eventos»…: the panel's own name, per app. */
    public static function panelLabel(): string
    {
        return Lang::has('nexo.app.panel') ? (string) __('nexo.app.panel') : (string) __('nexo.account.panel');
    }

    /**
     * This app's own account page, for an app that keeps local accounts next to
     * Nexo ID (Nexo Links' /profile: the password of an account not linked yet,
     * deleting the account), or null. Opt-in per app, nothing to do elsewhere:
     * config('nexo.chrome.account_route') names the route and lang
     * nexo.app.account its label. The account menu and the phone sheet list it
     * right after «Tu panel».
     */
    public static function appAccountUrl(): ?string
    {
        $route = config('nexo.chrome.account_route');
        if (! is_string($route) || $route === '' || ! Route::has($route) || ! Lang::has('nexo.app.account')) {
            return null;
        }

        return route($route);
    }

    /** The label of appAccountUrl() («Perfil» in Nexo Links). */
    public static function appAccountLabel(): string
    {
        return (string) __('nexo.app.account');
    }

    public static function helpUrl(): ?string
    {
        return Route::has('help') ? route('help') : null;
    }

    /**
     * Where a tool's switcher row points. With a session and SSO on (or in Nexo
     * ID itself), every tool opens its panel already signed in (`url + entry`,
     * CONTRATO §4); without a session it is the tool's landing, so a visitor
     * still meets the product.
     * This app itself, with a session, is its own panel — no round trip.
     *
     * @param  array<string, mixed>  $tool
     */
    public static function switcherHref(string $key, array $tool): string
    {
        $url = rtrim((string) ($tool['url'] ?? ''), '/');

        // In Nexo ID the session IS the shared one the tools ride, so the entry
        // applies there without any SSO client config.
        $sharedSession = self::ssoEnabled() || self::current() === 'nexoid';

        if (! auth()->check() || ! $sharedSession) {
            return $url !== '' ? $url : '/';
        }

        if ($key === self::current()) {
            return self::panelUrl();
        }

        $entry = (string) ($tool['entry'] ?? '');

        return $url.($entry !== '' ? '/'.ltrim($entry, '/') : '');
    }

    /** Up to two initials for the account button («Ana Vidal» → «AV»). */
    public static function initials(?Authenticatable $user = null): string
    {
        $user ??= auth()->user();
        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            $name = (string) ($user->email ?? '?');
        }

        $words = preg_split('/[\s._@-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }
}
