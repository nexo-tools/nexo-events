<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Request-level rules shared by the silent attempt, the session guard and the
 * entry/re-validation routes: which requests may be bounced to Nexo ID at all,
 * and which return destinations are safe.
 */
final class NexoSsoRequest
{
    /** Auth screens the browser must never be bounced away from (§2.6). */
    private const AUTH_ROUTES = ['login', 'register', 'password.*', 'verification.*', 'nexo-sso.*'];

    /**
     * A top-level GET page navigation: the only kind of request that may be
     * redirected to the provider. Never a POST (a form would lose its body),
     * never JSON or XHR whatever its Accept header (a fetch can't follow a
     * cross-site login), never a prefetch, never a subresource (Sec-Fetch-Mode
     * other than navigate).
     */
    public static function isPageNavigation(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax() || $request->pjax()) {
            return false;
        }

        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch')) {
            return false;
        }

        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $mode === null || $mode === '' || strtolower($mode) === 'navigate';
    }

    /** /auth/nexo/* and the auth screens: no silent attempt, no re-validation bounce. */
    public static function isAuthSurface(Request $request): bool
    {
        return $request->is('auth/nexo', 'auth/nexo/*') || $request->routeIs(self::AUTH_ROUTES);
    }

    /**
     * Surfaces no Nexo ID bounce may start from: the tool's fallback route (an
     * unknown URL — the 404 page must render, not detour through Nexo ID), and
     * the public surfaces the tool listed in config (paths and route names).
     */
    public static function isExcluded(Request $request): bool
    {
        $route = $request->route();
        if ($route instanceof Route && $route->isFallback) {
            return true;
        }

        $paths = (array) config('nexo-sso.silent_excluded', []);
        if ($paths !== [] && $request->is($paths)) {
            return true;
        }

        $routes = (array) config('nexo-sso.silent_excluded_routes', []);

        return $routes !== [] && $request->routeIs($routes);
    }

    /**
     * A `to` destination is accepted only as a path of THIS origin: it starts
     * with a single "/", carries no scheme, host, backslash or control
     * character, and does not point back into /auth/nexo (a loop). Anything
     * else — https://evil, //evil, /\evil, javascript:, garbage — is null and
     * the caller falls back to the panel. (AC-ENTER, anti open-redirect)
     */
    public static function safePath(mixed $to): ?string
    {
        if (! is_string($to) || $to === '' || strlen($to) > 2048) {
            return null;
        }

        if ($to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('/[\x00-\x1F\x7F]/', $to) === 1) {
            return null;
        }

        $parts = parse_url($to);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        if ($path === '/auth/nexo' || str_starts_with($path, '/auth/nexo/')) {
            return null;
        }

        return $to;
    }

    /** The tool's panel path (config `home`, an adaptation point), always safe. */
    public static function home(): string
    {
        return self::safePath(config('nexo-sso.home', '/')) ?? '/';
    }

    /** This request as a same-origin path with its query, for a later `to`. */
    public static function currentPath(Request $request): string
    {
        $relative = '/'.ltrim(Str::after($request->fullUrl(), $request->root()), '/');

        return self::safePath($relative) ?? self::home();
    }
}
