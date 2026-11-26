<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\NexoSsoSilentLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\OperatorAlerts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

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
            // Silent SSO trigger (prompt=none) — pass-through unless NEXO_SSO_ENABLED.
            NexoSsoSilentLogin::class,
        ]);

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
