<?php

use App\Http\Middleware\NexoSsoSilentLogin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
