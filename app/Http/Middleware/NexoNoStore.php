<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Cache-Control: no-store, private` on everything that carries a session
 * (STANDARD «no-store», CONTRATO-F3 §3). Copy to
 * app/Http/Middleware/NexoNoStore.php — IDENTICAL IN THE 6 APPS — and append it
 * to the `web` group (after StartSession, so the user resolves):
 *
 *   $middleware->web(append: [..., NexoNoStore::class]);
 *
 * Why: after «Cerrar sesión», the back button showed the Nexo ID profile (name
 * and email) and the Events and Links panels from the browser cache
 * (H-cruce-03, verified 2026-11-24). Laravel's default `no-cache, private`
 * allows the browser to keep the page; only `no-store` forbids it.
 *
 * Covered: every response to a signed-in request, and the auth screens even for
 * a guest (a login page with a CSRF token or a validation error must not come
 * back from cache either). Public pages for guests stay cacheable.
 * Guarded by tests/Feature/Nexo/NoStoreTest.php.
 */
final class NexoNoStore
{
    /** Auth surfaces that are no-store for guests too (paths, Request::is). */
    public const AUTH_PATHS = [
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'reset-password/*',
        'verify-email',
        'verify-email/*',
        'confirm-password',
        'two-factor-challenge',
        'auth/nexo/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() !== null || $request->is(self::AUTH_PATHS)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
