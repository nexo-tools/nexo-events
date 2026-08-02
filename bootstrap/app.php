<?php

use App\Http\Middleware\NexoSsoSilentLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Mail\OperatorAlert;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Something broke and nobody is watching: this ecosystem has no error
        // tracker by design (a third party observing users contradicts the
        // product), so the operator hears about a 500 by mail. Deduped by
        // exception identity for 15 minutes — a loop must not flood an inbox
        // until its owner stops reading it. See templates/nexo-ops/README.md.
        $exceptions->report(function (Throwable $e): void {
            // Off unless the operator turned it on — which is also what keeps
            // a suite quiet, since the flag is false in the testing env.
            if (! config('nexo.ops_mail', false)) {
                return;
            }

            $recipient = (string) config('nexo.support_email');
            if ($recipient === '') {
                return;
            }

            $key = 'ops-mail:'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());
            if (! Cache::add($key, true, now()->addMinutes(15))) {
                return;
            }

            Mail::to($recipient)->queue(OperatorAlert::fromThrowable($e, request()?->fullUrl()));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
