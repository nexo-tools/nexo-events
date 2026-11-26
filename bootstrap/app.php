<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\NexoNoStore;
use App\Http\Middleware\NexoSsoSessionGuard;
use App\Http\Middleware\NexoSsoSilentLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\NexoSso\NexoAuthMode;
use App\Services\NexoSso\NexoSsoStaleLogout;
use App\Support\OperatorAlerts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Integration API (ADR-010). Registered unconditionally — the env gate
        // lives in the middleware, because routes that exist only sometimes are
        // routes whose absence `route:cache` can bake in.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Theme and language ride a cookie scoped to the parent domain so a
        // choice made in one Nexo tool holds in the others. Laravel encrypts
        // cookies with THIS app's key, which the sibling tools do not have — an
        // encrypted value would be unreadable to them (and to the inline
        // theme-init script). These two carry no secrets.
        $middleware->encryptCookies(except: ['nexo-theme', 'nexo-lang']);

        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
            // Nexo ID controls the session (nexo-sso-client v2): back-channel
            // revocations, the periodic prompt=none re-validation (GET page
            // navigations only — never the door's JSON check-in POST) and, in
            // sso mode, closing a session Nexo ID does not govern. Then the
            // silent SSO trigger for guests. Both pass-through unless
            // NEXO_SSO_ENABLED; e/* and t/* are excluded (config/nexo-sso.php).
            NexoSsoSessionGuard::class,
            NexoSsoSilentLogin::class,
            // no-store on everything with a session and on the auth screens, so
            // the back button never shows a signed-out person a private page.
            NexoNoStore::class,
        ]);

        // Where `auth` sends a guest. SSO-only (NEXO_AUTH_MODE=sso): a deep link
        // — a collaborator invitation, a bookmarked event — goes through Nexo ID
        // and comes back to itself, never showing /login. Otherwise the login
        // page, as before.
        $middleware->redirectGuestsTo(fn (Request $request): string => NexoAuthMode::guestRedirectUrl($request));

        $middleware->alias(['api.token' => AuthenticateApiToken::class]);

        // Order matters and is not the order written on the route. Laravel sorts
        // a route's middleware by this priority list, and ThrottleRequests is in
        // it — so without this line the throttle jumps ahead of the API auth and
        // two things break quietly: an instance with the API off starts
        // answering 429 instead of 404, and the limiter keys by IP instead of by
        // token (every consumer behind one NAT sharing a bucket). Asserted by
        // test, because the next person to touch middleware cannot be expected
        // to know this.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateApiToken::class,
        );

        // The language is set before route-model binding, so a 404 from a
        // missing {event}/{business} (a mistyped or deleted shared link) speaks
        // the visitor's language, not the default. SetLocale is appended to
        // the web group, after SubstituteBindings, and binding failed first
        // (usability pass, F3 2026-11-26).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetLocale::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Something broke and nobody is watching: this ecosystem has no error
        // tracker by design (a third party observing users contradicts the
        // product), so the operator hears about it by mail. Deduped by
        // exception identity for 15 minutes, and a queued job only once it
        // gives up — never on an attempt that is retried and then works.
        // The queue half is wired in ->booted() below. See
        // templates/nexo-ops/README.md.
        $exceptions->report(fn (Throwable $e) => OperatorAlerts::report($e));
        // «Cerrar sesión» from a page whose session expired (419): no "Page
        // expired" — Nexo ID's end_session without a hint, which asks before
        // ending anything (nexo-sso-client v2.1). Any other 419 is untouched.
        $exceptions->render(fn (HttpException $e, Request $request) => NexoSsoStaleLogout::render($e, $request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })
    // The other half of the operator alert: a queued job's attempts are
    // remembered and its final failure is the one that mails. Registered here,
    // not in the exception callback, so the listeners exist before the first
    // job runs whether or not the handler has been resolved yet.
    ->booted(fn (Application $app) => OperatorAlerts::listen($app['events']))
    ->create();
