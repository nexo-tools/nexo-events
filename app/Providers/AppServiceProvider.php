<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // The family mail layout lives under resources/views/emails/ rather than
        // resources/views/components/ because that is where hex literals are
        // allowed (NoHardcodedColorsTest) — and a mail needs them: clients strip
        // <style> and know nothing about the design tokens. This line gives it
        // the normal component syntax: <x-nexo-mail::layout>.
        Blade::anonymousComponentPath(resource_path('views/emails/nexo'), 'nexo-mail');

        $this->registerApiRateLimiters();
    }

    /**
     * Integration API limits (ADR-010, AC-API-13). Keyed by TOKEN, not by IP:
     * one organizer's misbehaving integration must not throttle another's, and
     * two consumers behind the same NAT are two consumers.
     *
     * The key is derived from the bearer here rather than read off the resolved
     * token, so it holds no matter where the throttle ends up in the middleware
     * stack — the hash is the same value the lookup uses, and an unauthenticated
     * request has nothing to key by but its address.
     */
    private function registerApiRateLimiters(): void
    {
        RateLimiter::for('nexo-api-read', fn (Request $request) => Limit::perMinute((int) config('nexo.api.rate_read', 60))->by($this->apiRateKey($request)));

        RateLimiter::for('nexo-api-write', fn (Request $request) => Limit::perMinute((int) config('nexo.api.rate_write', 20))->by($this->apiRateKey($request)));
    }

    private function apiRateKey(Request $request): string
    {
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        if ($token instanceof ApiToken) {
            return 'api-token:'.$token->getKey();
        }

        $raw = (string) $request->bearerToken();

        return $raw !== ''
            ? 'api-token-hash:'.ApiToken::hashFor($raw)
            : 'api-ip:'.(string) $request->ip();
    }
}
